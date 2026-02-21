<?php

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Composer\ProjectComposerPackage;
use BrianHenryIE\Strauss\Pipeline\Copier;
use BrianHenryIE\Strauss\Pipeline\FileCopyScanner;
use BrianHenryIE\Strauss\Pipeline\FileEnumerator;
use BrianHenryIE\Strauss\IntegrationTestCase;
use Psr\Log\NullLogger;
use stdClass;

/**
 * Class CopierTest
 * @package BrianHenryIE\Strauss
 * @coversNothing
 */
class CopierIntegrationTest extends IntegrationTestCase
{

    public function testPrepareTarget(): void
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "league/container": "*"
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
            $this->getFileSystem(),
            $this->getLogger()
        );
        $files = $fileEnumerator->compileFileListForDependencies($dependencies);

        $fileCopyScanner = new FileCopyScanner($config, $this->getFileSystem());
        $fileCopyScanner->scanFiles($files);

        $copier = new Copier($files, $config, $this->getFileSystem(), new NullLogger());

        $file = 'ContainerAwareTrait.php';
        $relativePath = 'league/container/src/';
        $targetPath = $targetDir . $relativePath;
        $targetFile = $targetPath . $file;

        mkdir(rtrim($targetPath, '\\/'), 0777, true);

        $this->getFileSystem()->write($targetFile, 'dummy file');

        assert(file_exists($targetFile));

        $copier->prepareTarget();

        $this->assertFalse($this->getFileSystem()->fileExists($targetFile));
    }

    public function testsCopy(): void
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/copierintegrationtest",
  "require": {
    "google/apiclient": "v2.16.1"
  },
  "config": {
    "audit": {
      "block-insecure": false
    }
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
            $this->getFileSystem(),
            $this->getLogger()
        );
        $files = $fileEnumerator->compileFileListForDependencies($dependencies);

        (new FileCopyScanner($config, $this->getFileSystem()))->scanFiles($files);

        $copier = new Copier($files, $config, $this->getFileSystem(), new NullLogger());

        $file = 'Client.php';
        $relativePath = 'google/apiclient/src/';
        $targetPath = $targetDir . $relativePath;
        $targetFile = $targetPath . $file;

        $copier->prepareTarget();

        $copier->copy();

        $this->assertTrue($this->getFileSystem()->fileExists($targetFile));
    }




    /**
     * Set up a common settings object.
     * @see MoverTest.php
     */
    protected function createComposer(): void
    {
        $mozartConfig = new stdClass();
        $mozartConfig->dep_directory = "/dep_directory/";
        $mozartConfig->classmap_directory = "/classmap_directory/";
        $mozartConfig->packages = array(
            "pimple/pimple",
            "ezyang/htmlpurifier"
        );

        $pimpleAutoload = new stdClass();
        $pimpleAutoload->{'psr-0'} = new stdClass();
        $pimpleAutoload->{'psr-0'}->Pimple = "src/";

        $htmlpurifierAutoload = new stdClass();
        $htmlpurifierAutoload->classmap = new stdClass();
        $htmlpurifierAutoload->classmap->Pimple = "library/";

        $mozartConfig->override_autoload = array();
        $mozartConfig->override_autoload["pimple/pimple"] = $pimpleAutoload;
        $mozartConfig->override_autoload["ezyang/htmlpurifier"] = $htmlpurifierAutoload;

        $composer = new stdClass();
        $composer->extra = new stdClass();
        $composer->extra->mozart = $mozartConfig;

        $composerFilepath = $this->testsWorkingDir . 'composer.json';
        $composerJson = json_encode($composer) ;
        $this->getFileSystem()->write($composerFilepath, $composerJson);

        $this->config = StraussConfig::loadFromFile($composerFilepath);
    }

    /**
     * If the specified `dep_directory` or `classmap_directory` are absent, create them.
     * @see MoverTest.php
     * @test
     */
    public function it_creates_absent_dirs(): void
    {
        $this->markTestIncomplete();

        $mover = new Mover($this->testsWorkingDir, $this->config);

        // Make sure the directories don't exist.
        assert(! file_exists($this->testsWorkingDir . $this->config->gett()), "{$this->testsWorkingDir}{$this->config->getDepDirectory()} already exists");
        assert(! file_exists($this->testsWorkingDir . $this->config->getClassmapDirectory()));

        $packages = array();

        $mover->deleteTargetDirs($packages);

        self::assertTrue(file_exists($this->testsWorkingDir
            . $this->config->getDepDirectory()));
        self::assertTrue(file_exists($this->testsWorkingDir
            . $this->config->getClassmapDirectory()));
    }

    /**
     * If the specified `dep_directory` or `classmap_directory` already exists with contents, it is not an issue.
     *
     * @see MoverTest.php
     *
     * @test
     */
    public function it_is_unpertrubed_by_existing_dirs(): void
    {
        $this->markTestIncomplete();

        $mover = new Mover($this->testsWorkingDir, $this->config);

        if (!file_exists($this->testsWorkingDir . $this->config->getDepDirectory())) {
            mkdir($this->testsWorkingDir . $this->config->getDepDirectory());
        }
        if (!file_exists($this->testsWorkingDir . $this->config->getClassmapDirectory())) {
            mkdir($this->testsWorkingDir . $this->config->getClassmapDirectory());
        }

        self::assertDirectoryExists($this->testsWorkingDir . $this->config->getDepDirectory());
        self::assertDirectoryExists($this->testsWorkingDir . $this->config->getClassmapDirectory());

        $packages = array();

        ob_start();

        $mover->deleteTargetDirs($packages);

        $output = ob_get_clean();

        self::assertEmpty($output);
    }

    /**
     * If the specified `dep_directory` or `classmap_directory` contains a subdir we are going to need when moving,
     * delete the subdir. aka:  If subfolders exist for dependencies we are about to manage, delete those subfolders.
     *
     * @see MoverTest.php
     *
     * @test
     */
    public function it_deletes_subdirs_for_packages_about_to_be_moved(): void
    {
        $this->markTestIncomplete();

        $mover = new Mover($this->testsWorkingDir, $this->config);

        @mkdir($this->testsWorkingDir . $this->config->getDepDirectory());
        @mkdir($this->testsWorkingDir . $this->config->getClassmapDirectory());

        @mkdir($this->testsWorkingDir . $this->config->getDepDirectory() . 'Pimple');
        @mkdir($this->testsWorkingDir . $this->config->getClassmapDirectory() . 'ezyang');

        $packages = array();
        foreach ($this->config->getPackages() as $packageString) {
            $testDummyComposerDir = $this->testsWorkingDir  . 'vendor/' . $packageString;
            @mkdir($testDummyComposerDir, 0777, true);
            $testDummyComposerPath = $testDummyComposerDir . '/composer.json';
            $testDummyComposerContents = json_encode(new stdClass());

            $this->getFileSystem()->write($testDummyComposerPath, $testDummyComposerContents);
            $parsedPackage = new ComposerPackageConfig($testDummyComposerDir, $this->config->getOverrideAutoload()[$packageString]);
            $parsedPackage->findAutoloaders();
            $packages[] = $parsedPackage;
        }

        $mover->deleteTargetDirs($packages);

        self::assertDirectoryDoesNotExist($this->testsWorkingDir . $this->config->getDepDirectory() . 'Pimple');
        self::assertDirectoryDoesNotExist($this->testsWorkingDir . $this->config->getDepDirectory() . 'ezyang');
    }
}
