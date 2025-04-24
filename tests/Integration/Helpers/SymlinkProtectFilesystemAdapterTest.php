<?php
/**
 * Delete operations to symlinks should be changed to unlink.
 */

namespace BrianHenryIE\Strauss\Helpers;

use BrianHenryIE\ColorLogger\ColorLogger;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Helpers\SymlinkProtectFilesystemAdapter
 */
class SymlinkProtectFilesystemAdapterTest extends \BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase
{
    public function test_delete(): void
    {
        $rootFilesystem = new LocalFilesystemAdapter($this->testsWorkingDir);

        mkdir($this->testsWorkingDir . '/realdir');

        symlink($this->testsWorkingDir . '/realdir', $this->testsWorkingDir . '/fakedir');

        $logger = new ColorLogger();

        $sut = new SymlinkProtectFilesystemAdapter($rootFilesystem, '/', $logger);

        $filesystem = new \League\Flysystem\FileSystem($sut);

        $filesystem->delete('fakedir');

        $this->assertTrue($logger->hasNoticeRecords());

        $this->assertDirectoryExists($this->testsWorkingDir . '/realdir');
    }
}
