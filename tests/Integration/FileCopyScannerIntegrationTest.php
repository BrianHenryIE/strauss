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
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\NullLogger;

/**
 * Class CopierTest
 * @package BrianHenryIE\Strauss
 * @coversNothing
 */
class FileCopyScannerIntegrationTest extends IntegrationTestCase
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

        $targetDir = $this->testsWorkingDir . 'vendor-prefixed/';
        $vendorDir = $this->testsWorkingDir . 'vendor/';

        $config = $this->createStub(StraussConfig::class);
        $config->method('getVendorDirectory')->willReturn($vendorDir);
        $config->method('getTargetDirectory')->willReturn($targetDir);

        $fileEnumerator = new FileEnumerator(
            $config,
            new Filesystem(
                new \League\Flysystem\Filesystem(
                    new LocalFilesystemAdapter('/')
                ),
                $this->testsWorkingDir
            ),
            $this->getLogger()
        );

        $files = $fileEnumerator->compileFileListForDependencies($dependencies);
        foreach ($files->getFiles() as $file) {
            $file->setDoPrefix($file->isPhpFile());
        }

        (new FileCopyScanner($config, new Filesystem(new \League\Flysystem\Filesystem(new LocalFilesystemAdapter('/')), $this->testsWorkingDir)))->scanFiles($files);

        $copier = new Copier($files, $config, new Filesystem(new \League\Flysystem\Filesystem(new LocalFilesystemAdapter('/')), $this->testsWorkingDir), new NullLogger());

        $copier->prepareTarget();

        $copier->copy();

        $config = $this->createStub(StraussConfig::class);

        $config->method('getNamespacePrefix')->willReturn("Prefix");
        $config->method('getExcludeNamespacesFromPrefixing')->willReturn(array());
        $config->method('getExcludePackagesFromPrefixing')->willReturn(array());
        $config->method('getPackagesToPrefix')->willReturn(array('google/apiclient'=>''));

        $discoveredSymbols = new DiscoveredSymbols();

        $fileScanner = new FileSymbolScanner($config, $discoveredSymbols, new Filesystem(new \League\Flysystem\Filesystem(new LocalFilesystemAdapter('/')), $this->testsWorkingDir));

        $discoveredSymbols = $fileScanner->findInFiles($files);

        $classes = $discoveredSymbols->getDiscoveredClasses();

        $namespaces = $discoveredSymbols->getDiscoveredNamespaces();

        self::assertNotEmpty($classes);
        self::assertNotEmpty($namespaces);

        self::assertContains('Google_Task_Composer', $classes);
    }

    /**
     * Fix: "preg_match(): Delimiter must not be alphanumeric or backslash"
     *
     * @see FileCopyScanner::isFilePathExcluded()
     */
    public function test_exclude_copy_file_patterns(): void
    {

        $composerJsonString = <<<'EOD'
{
    "name": "brianhenryie/file-copy-scanner",
    "require": {
        "wordpress/mcp-adapter": "0.3.0"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "BrianHenryIE\\Strauss\\",
            "target_directory": "vendor-prefixed",
            "delete_vendor_packages": true,
	        "exclude_from_copy": {
	          "file_patterns": [
	            "wordpress/mcp-adapter/.github",
	            "wordpress/mcp-adapter/docs",
	            "wordpress/mcp-adapter/tests",
	            "wordpress/mcp-adapter/CONTRIBUTING.md",
	            "wordpress/mcp-adapter/phpcs.xml.dist",
	            "wordpress/mcp-adapter/phpunit.xml.dist",
	            "wordpress/mcp-adapter/README-INITIAL.md",
	            "wordpress/mcp-adapter/phpstan.neon.dist"
	          ]
	        }
        }
    }
}
EOD;
        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $this->assertFileDoesNotExist($this->testsWorkingDir . 'vendor-prefixed/wordpress/mcp-adapter/phpunit.xml.dist');
    }
}
