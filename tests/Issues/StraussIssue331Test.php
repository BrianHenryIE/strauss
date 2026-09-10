<?php
/**
 * `[error] Unable to realpath() absolute path` when both `delete_vendor_packages` and `delete_vendor_files` are enabled.
 *
 * `Cleanup::deleteFiles()` first deletes each package directory, then deletes each file individually. By the time
 * the individual files are processed they no longer exist, and `SymlinkProtectFilesystemAdapter::delete()` throws
 * when `realpath()` fails, whereas Flysystem's `LocalFilesystemAdapter::delete()` is a no-op for missing files.
 *
 * @see \BrianHenryIE\Strauss\Pipeline\Cleanup\Cleanup::deleteFiles()
 * @see \BrianHenryIE\Strauss\Helpers\Flysystem\SymlinkProtectFilesystemAdapter::delete()
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/331
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue331Test extends IntegrationTestCase
{

    public function test_delete_vendor_packages_and_delete_vendor_files_together(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "strauss/issue331",
  "require": {
    "psr/log": "1.1.4"
  },
  "extra": {
    "strauss": {
      "target_directory": "vendor-prefixed",
      "namespace_prefix": "Strauss\\Issue331\\",
      "classmap_prefix": "Strauss_Issue331_",
      "delete_vendor_packages": true,
      "delete_vendor_files": true
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $this->assertFileExistsInFileSystem($this->testsWorkingDir . '/vendor/psr/log/composer.json');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $this->assertStringNotContainsString('Unable to realpath()', $output);

        $this->assertFileExistsInFileSystem($this->testsWorkingDir . '/vendor-prefixed/psr/log/Psr/Log/LoggerInterface.php');
        $this->assertFileNotExistsInFileSystem($this->testsWorkingDir . '/vendor/psr/log/composer.json');
        $this->assertDirectoryNotExistsInFileSystem($this->testsWorkingDir . '/vendor/psr/log');
    }
}
