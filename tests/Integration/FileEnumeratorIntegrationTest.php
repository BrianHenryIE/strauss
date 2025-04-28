<?php

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Composer\ProjectComposerPackage;
use BrianHenryIE\Strauss\Pipeline\FileEnumerator;
use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;
use BrianHenryIE\Strauss\Helpers\FileSystem;
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

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

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
            new Filesystem(
                new LocalFilesystemAdapter('/')
            )
        );

        $files = $fileEnumerator->compileFileListForDependencies($dependencies);

        $this->assertNotNull($files->getFile($this->pathNormalizer->normalizePath($workingDir . 'vendor/google/apiclient/src/aliases.php')));
    }
}
