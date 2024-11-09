<?php

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\FileCopyScanner;
use BrianHenryIE\Strauss\FileSymbolScanner;
use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Composer\ProjectComposerPackage;
use BrianHenryIE\Strauss\Copier;
use BrianHenryIE\Strauss\FileEnumerator;
use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * Class CopierTest
 * @package BrianHenryIE\Strauss
 * @coversNothing
 */
class FileScannerIntegrationTest extends IntegrationTestCase
{

    /**
     * Given a list of files, find all the global classes and the namespaces.
     */
    public function testOne()
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
      "classmap_prefix": "BrianHenryIE_Strauss_",
      "delete_vendor_files": false
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $projectComposerPackage = new ProjectComposerPackage($this->testsWorkingDir);

        $dependencies = array_map(function ($element) {
            $dir = $this->testsWorkingDir . 'vendor'. DIRECTORY_SEPARATOR . $element;
            return ComposerPackage::fromFile($dir);
        }, $projectComposerPackage->getRequiresNames());

        $workingDir = $this->testsWorkingDir;
        $relativeTargetDir = 'vendor-prefixed' . DIRECTORY_SEPARATOR;
        $vendorDir = 'vendor' . DIRECTORY_SEPARATOR;

        $config = $this->createStub(StraussConfig::class);
        $config->method('getVendorDirectory')->willReturn($vendorDir);
        $config->method('getTargetDirectory')->willReturn($relativeTargetDir);

        $fileEnumerator = new FileEnumerator($workingDir, $config);

        $files = $fileEnumerator->compileFileListForDependencies($dependencies);

        (new FileCopyScanner($workingDir, $config))->scanFiles($files);

        $copier = new Copier($files, $workingDir, $config);

        $copier->prepareTarget();

        $copier->copy();

        $config = $this->createStub(StraussConfig::class);

        $config->method('getNamespacePrefix')->willReturn("Prefix");
        $config->method('getExcludeNamespacesFromPrefixing')->willReturn(array());
        $config->method('getExcludePackagesFromPrefixing')->willReturn(array());

        $fileScanner = new FileSymbolScanner($config);

        $discoveredSymbols = $fileScanner->findInFiles($files);

        $classes = $discoveredSymbols->getDiscoveredClasses();

        $namespaces = $discoveredSymbols->getDiscoveredNamespaces();

        self::assertNotEmpty($classes);
        self::assertNotEmpty($namespaces);

        self::assertContains('Google_Task_Composer', $classes);
    }
}
