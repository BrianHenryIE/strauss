<?php

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Composer\ProjectComposerPackage;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Pipeline\FileEnumerator;
use BrianHenryIE\Strauss\IntegrationTestCase;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * Class FileEnumeratorIntegrationTest
 * @package BrianHenryIE\Strauss
 * @coversNothing
 */
class FileEnumeratorIntegrationTest extends IntegrationTestCase
{

    public function testBuildFileList()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/fileenumeratorintegrationtest",
  "require": {
    "google/apiclient": "*"
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

        $this->getFileSystem()->write($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $projectComposerPackage = new ProjectComposerPackage($this->testsWorkingDir . 'composer.json');

        // Only one because we haven't run "flat dependency list".
        $dependencies = array_map(function ($element) {
            $composerFile = $this->testsWorkingDir . 'vendor/' . $element . '/composer.json';
            $a = ComposerPackage::fromFile($composerFile);
            $a->setProjectVendorDirectory($this->testsWorkingDir . 'vendor/');
            return $a;
        }, $projectComposerPackage->getRequiresNames());

        $workingDir = $this->testsWorkingDir;
        $vendorDir = 'vendor/';

        $config = $this->createStub(StraussConfig::class);
        $config->method('getVendorDirectory')->willReturn($workingDir . $vendorDir);

        $fileEnumerator = new FileEnumerator(
            $config,
            $this->getFileSystem(),
            $this->getLogger()
        );

        $files = $fileEnumerator->compileFileListForDependencies($dependencies);

        $this->assertNotNull($files->getFile($this->pathNormalizer->normalizePath($workingDir . 'vendor/google/apiclient/src/aliases.php')));
    }

    public function test_exclude_from_classmap(): void
    {
        $this->markTestSkippedOnPhpVersionBelow('8.1');

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/exceludefromclassmap",
  "require": {
    "giggsey/libphonenumber-for-php-lite": "8.13.55"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\"
    }
  }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);
        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $this->assertFileExists($this->testsWorkingDir . '/vendor-prefixed/giggsey/libphonenumber-for-php-lite/src/data/PhoneNumberMetadata_800.php');
        // TODO: This test really should be to not prefix exclude-from-classmap files but these files are just arrays.
        // When I remember a package that has classes in exclude-from-classmap, I'll update this test.
    }
}
