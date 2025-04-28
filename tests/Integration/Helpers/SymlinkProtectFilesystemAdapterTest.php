<?php
/**
 * Delete operations to symlinks should be changed to unlink.
 */

namespace BrianHenryIE\Strauss\Helpers;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PathPrefixer;
use Psr\Log\Test\TestLogger;
use League\Flysystem\Filesystem as FlysystemFilesystem;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Helpers\SymlinkProtectFilesystemAdapter
 */
class SymlinkProtectFilesystemAdapterTest extends IntegrationTestCase
{
    protected TestLogger $logger;

    protected FlysystemFilesystem $filesystem;

    public function setUp(): void
    {
        parent::setUp();

        mkdir($this->testsWorkingDir . '/realdir');
        mkdir($this->testsWorkingDir . '/realdir/subdir');
        // aka /fakedir/subdir
        file_put_contents($this->testsWorkingDir . '/realdir/file.txt', 'test');
        // aka /fakedir/file.txt
        symlink($this->testsWorkingDir . '/realdir', $this->testsWorkingDir . '/fakedir');

        $rootFilesystem = new LocalFilesystemAdapter($this->testsWorkingDir);

        $this->logger = new ColorLogger();

        $sut = new SymlinkProtectFilesystemAdapter(
            $rootFilesystem,
            new PathPrefixer($this->testsWorkingDir),
            null,
            $this->logger
        );

        $this->filesystem = new \League\Flysystem\FileSystem($sut);
    }

    public function tearDown(): void
    {
        $this->assertDirectoryExists($this->testsWorkingDir . '/realdir');
        $this->assertDirectoryExists($this->testsWorkingDir . '/realdir/subdir');
        $this->assertFileExists($this->testsWorkingDir . '/realdir/file.txt');

        parent::tearDown();
    }

    /**
     * When deleting a "directory" symlink
     * A notice should be logged
     * And the target directory should not be deleted
     * And the symlink should be deleted
     */
    public function test_delete_symlinked_directory(): void
    {
        $this->filesystem->deleteDirectory('fakedir');

        $this->assertTrue($this->logger->hasNoticeRecords());

        $this->assertDirectoryDoesNotExist($this->testsWorkingDir . '/fakedir');
    }

    /**
     * When deleting a directory inside a symlinked directory
     * An error should be logged
     * And the symlink should not be deleted
     */
    public function test_delete_directory_in_symlinked_directory(): void
    {
        $this->filesystem->deleteDirectory('fakedir/subdir');

        $this->assertTrue($this->logger->hasErrorRecords());

        $this->assertDirectoryExists($this->testsWorkingDir . '/fakedir');
    }

    /**
     * When deleting a file inside a symlinked directory
     * An error should be logged
     * And the target file should not be deleted
     * And the symlink should not be deleted
     */
    public function test_delete_file_in_symlinked_directory(): void
    {
        $this->filesystem->delete('fakedir/file.txt');

        $this->assertTrue($this->logger->hasErrorRecords());

        $this->assertFileExists($this->testsWorkingDir . '/realdir/file.txt');
        $this->assertFileExists($this->testsWorkingDir . '/fakedir/file.txt');
        $this->assertDirectoryExists($this->testsWorkingDir . '/realdir');
    }

    /**
     * When writing a file inside a symlinked directory
     * A warning should be logged
     * And the target file should not be written to
     */
    public function test_write_file_in_symlinked_directory(): void
    {
        $this->filesystem->write('fakedir/file2.txt', 'test');

        $this->assertTrue($this->logger->hasWarningRecords());

        $this->assertFileDoesNotExist($this->testsWorkingDir . '/realdir/file2.txt');
        $this->assertFileDoesNotExist($this->testsWorkingDir . '/fakedir/file2.txt');
    }
}
