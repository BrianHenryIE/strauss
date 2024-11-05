<?php

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\ChangeEnumerator;
use BrianHenryIE\Strauss\FileScanner;
use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Composer\ProjectComposerPackage;
use BrianHenryIE\Strauss\Console\Commands\Compose;
use BrianHenryIE\Strauss\Copier;
use BrianHenryIE\Strauss\FileEnumerator;
use BrianHenryIE\Strauss\Prefixer;
use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ReplacerIntegrationTest
 * @package BrianHenryIE\Strauss\Tests\Integration
 * @coversNothing
 */
class ReplacerIntegrationTest extends IntegrationTestCase
{

    public function testReplaceNamespace()
    {
        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "google/apiclient": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_"
    },
    "google/apiclient-services": [
	  "Calendar"
	]
  },
  "scripts": {
    "delete-unused-google-apis": [
        "Google\\Task\\Composer::cleanup"
    ]
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $projectComposerPackage = new ProjectComposerPackage($this->testsWorkingDir);
        $input = $this->createMock(InputInterface::class);
        $config = $projectComposerPackage->getStraussConfig($input);

        $dependencies = array_map(function ($element) {
            $dir = $this->testsWorkingDir . 'vendor'. DIRECTORY_SEPARATOR . $element;
            return ComposerPackage::fromFile($dir);
        }, $projectComposerPackage->getRequiresNames());

        $workingDir = $this->testsWorkingDir;
        $relativeTargetDir = 'vendor-prefixed' . DIRECTORY_SEPARATOR;
        $absoluteTargetDir = $workingDir . $relativeTargetDir;

//        $config = $this->createStub(StraussConfig::class);
//        $config->method('getTargetDirectory')->willReturn('vendor-prefixed' . DIRECTORY_SEPARATOR);

        $fileEnumerator = new FileEnumerator($workingDir, $config);
        $files = $fileEnumerator->compileFileListForDependencies($dependencies);
        $phpFileList = $files->getPhpFilesAndDependencyList();

        $fileEnumerator = new FileEnumerator($workingDir, $config);
        $files = $fileEnumerator->compileFileListForDependencies($dependencies);

        $copier = new Copier($files, $workingDir, $config);
        $copier->prepareTarget();
        $copier->copy();

        $fileScanner = new FileScanner($config);
        $discoveredSymbols = $fileScanner->findInFiles($files);

        $changeEnumerator = new ChangeEnumerator($config, $workingDir);
        $changeEnumerator->determineReplacements($discoveredSymbols);

        $replacer = new Prefixer($config, $workingDir);

        $replacer->replaceInFiles($discoveredSymbols, $phpFileList);

        $updatedFile = file_get_contents($absoluteTargetDir . 'google/apiclient/src/Client.php');

        self::assertStringContainsString('use BrianHenryIE\Strauss\Google\AccessToken\Revoke;', $updatedFile);
    }


    public function testReplaceClass()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "setasign/fpdf": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_",
      "delete_vendor_files": false
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $inputInterfaceMock = $this->createMock(InputInterface::class);
        $outputInterfaceMock = $this->createMock(OutputInterface::class);

        $mozartCompose = new Compose();

        $result = $mozartCompose->run($inputInterfaceMock, $outputInterfaceMock);


//        $projectComposerPackage = new ProjectComposerPackage($this->testsWorkingDir);
//        $config = $projectComposerPackage->getStraussConfig();
//
//        $dependencies = array_map(function ($element) {
//            $dir = $this->testsWorkingDir . 'vendor'. DIRECTORY_SEPARATOR . $element;
//            return ComposerPackage::fromFile($dir);
//        }, $projectComposerPackage->getRequiresNames());
//
//        $workingDir = $this->testsWorkingDir;
//        $relativeTargetDir = 'vendor-prefixed' . DIRECTORY_SEPARATOR;
//        $absoluteTargetDir = $workingDir . $relativeTargetDir;
//
//        $fileEnumerator = new FileEnumerator($dependencies, $workingDir);
//        $fileEnumerator->compileFileList();
//        $fileList = $fileEnumerator->getAllFilesAndDependencyList();
//        $phpFileList = $fileEnumerator->getPhpFilesAndDependencyList();
//
//        $copier = new Copier($fileList, $workingDir, $relativeTargetDir);
//        $copier->prepareTarget();
//        $copier->copy();
//
//        $fileScanner = new FileScanner();
//        $fileScanner->findInFiles($absoluteTargetDir, $phpFileList);
//        $namespaces = $fileScanner->getDiscoveredNamespaces();
//        $classes = $fileScanner->getDiscoveredClasses();
//
//        $replacer = new Replacer($config, $workingDir);
//
//        $replacer->replaceInFiles($namespaces, $classes, $phpFileList);

        $updatedFile = file_get_contents($this->testsWorkingDir .'vendor-prefixed/' . 'setasign/fpdf/fpdf.php');

        self::assertStringContainsString('class BrianHenryIE_Strauss_FPDF', $updatedFile);
    }

    public function testSimpleRepacementPatterns(): void
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "brianhenryie/bh-wp-logger": "*"
  },
  "minimum-stability": "dev",
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\MyProject\\",
      "namespace_replacement_patterns": {
        "~BrianHenryIE\\\\(.*)~" : "BrianHenryIE\\MyProject\\\\$1"
      }
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $this->runStrauss();

        $updatedFile = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/brianhenryie/bh-wp-logger/src/class-logger.php');

        self::assertStringContainsString('namespace BrianHenryIE\MyProject\WP_Logger;', $updatedFile);
    }

    public function testExaggeratedRepacementPatterns(): void
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "brianhenryie/bh-wp-logger": "*"
  },
  "minimum-stability": "dev",
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\MyProject\\",
      "namespace_replacement_patterns": {
        "~BrianHenryIE\\\\WP_Logger~" : "AnotherProject\\Logger"
      }
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $this->runStrauss();

        $updatedFile = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/brianhenryie/bh-wp-logger/src/class-logger.php');

        self::assertStringContainsString('namespace AnotherProject\Logger;', $updatedFile);
    }

    public function testRidiculousReplacementPatterns(): void
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "brianhenryie/bh-wp-logger": "*"
  },
  "minimum-stability": "dev",
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\MyProject\\",
      "namespace_replacement_patterns": {
        "~BrianHenryIE\\\\(.*)(\\\\.*)?~" : "AnotherProject\\\\$1\\\\MyProject$2"
      }
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $this->runStrauss();

        $updatedFile = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/brianhenryie/bh-wp-logger/src/api/class-api.php');

        self::assertStringContainsString('namespace AnotherProject\WP_Logger\MyProject\API;', $updatedFile);
    }
}
