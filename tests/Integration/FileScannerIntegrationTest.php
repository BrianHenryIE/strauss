<?php

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Composer\ProjectComposerPackage;
use BrianHenryIE\Strauss\Pipeline\Copier;
use BrianHenryIE\Strauss\Pipeline\FileCopyScanner;
use BrianHenryIE\Strauss\Pipeline\FileEnumerator;
use BrianHenryIE\Strauss\Pipeline\FileSymbolScanner;
use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\NullLogger;

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
  "name": "brianhenryie/filescannerintegrationtest",
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

        $projectComposerPackage = new ProjectComposerPackage($this->testsWorkingDir . 'composer.json');

        $dependencies = array_map(function ($element) {
            $composerFile = $this->testsWorkingDir . 'vendor/' . $element . '/composer.json';
            return ComposerPackage::fromFile($composerFile);
        }, $projectComposerPackage->getRequiresNames());

        $workingDir = $this->testsWorkingDir;
        $relativeTargetDir = 'vendor-prefixed/';
        $vendorDir = 'vendor/';

        $config = $this->createStub(StraussConfig::class);
        $config->method('getVendorDirectory')->willReturn($vendorDir);
        $config->method('getTargetDirectory')->willReturn($relativeTargetDir);

        $fileEnumerator = new FileEnumerator($workingDir, $config, new Filesystem(new \League\Flysystem\Filesystem(new LocalFilesystemAdapter('/'))));

        $files = $fileEnumerator->compileFileListForDependencies($dependencies);

        (new FileCopyScanner($workingDir, $config, new Filesystem(new \League\Flysystem\Filesystem(new LocalFilesystemAdapter('/')))))->scanFiles($files);

        $copier = new Copier($files, $workingDir, $config, new Filesystem(new \League\Flysystem\Filesystem(new LocalFilesystemAdapter('/'))), new NullLogger());

        $copier->prepareTarget();

        $copier->copy();

        $config = $this->createStub(StraussConfig::class);

        $config->method('getNamespacePrefix')->willReturn("Prefix");
        $config->method('getExcludeNamespacesFromPrefixing')->willReturn(array());
        $config->method('getExcludePackagesFromPrefixing')->willReturn(array());

        $fileScanner = new FileSymbolScanner($config, new Filesystem(new \League\Flysystem\Filesystem(new LocalFilesystemAdapter('/'))));

        $discoveredSymbols = $fileScanner->findInFiles($files);

        $classes = $discoveredSymbols->getDiscoveredClasses();

        $namespaces = $discoveredSymbols->getDiscoveredNamespaces();

        self::assertNotEmpty($classes);
        self::assertNotEmpty($namespaces);

        self::assertContains('Google_Task_Composer', $classes);
    }
}
