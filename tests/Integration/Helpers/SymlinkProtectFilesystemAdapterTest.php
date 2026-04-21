<?php
/**
 * Delete operations to symlinks should be changed to unlink.
 */

namespace BrianHenryIE\Strauss\Helpers;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Helpers\SymlinkProtectFilesystemAdapter
 */
class SymlinkProtectFilesystemAdapterTest extends IntegrationTestCase
{
    protected FileSystem $filesystem;

    public function setUp(): void
    {
        parent::setUp();

        mkdir($this->testsWorkingDir . '/realdir');
        mkdir($this->testsWorkingDir . '/realdir/subdir');
        // aka /fakedir/subdir
        file_put_contents($this->testsWorkingDir . '/realdir/file.txt', 'test');
        // aka /fakedir/file.txt
        symlink($this->testsWorkingDir . '/realdir', $this->testsWorkingDir . '/fakedir');

        $sut = new SymlinkProtectFilesystemAdapter(
            FileSystem::getFsRoot($this->testsWorkingDir),
            null,
            null,
            $this->getTestLogger()
        );

        $this->filesystem = new FileSystem(
            $sut,
            [],
            FileSystem::makePathNormalizer($this->testsWorkingDir),
            null,
            $this->testsWorkingDir,
        );
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
        $this->filesystem->deleteDirectory($this->testsWorkingDir . '/fakedir');

        $this->assertTrue($this->getTestLogger()->hasNoticeRecords());

        $this->assertDirectoryDoesNotExist($this->testsWorkingDir . '/fakedir');
    }

    /**
     * When deleting a directory inside a symlinked directory
     * An error should be logged
     * And the symlink should not be deleted
     */
    public function test_delete_directory_in_symlinked_directory(): void
    {
        $this->filesystem->deleteDirectory($this->testsWorkingDir . '/fakedir/subdir');

        $this->assertTrue($this->getTestLogger()->hasErrorRecords());

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
        $this->filesystem->delete($this->testsWorkingDir . '/fakedir/file.txt');

        $this->assertTrue($this->getTestLogger()->hasErrorRecords());

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
        $this->filesystem->write($this->testsWorkingDir . '/fakedir/file2.txt', 'test');

        $this->assertTrue($this->getTestLogger()->hasWarningRecords());

        $this->assertFileDoesNotExist($this->testsWorkingDir . '/realdir/file2.txt');
        $this->assertFileDoesNotExist($this->testsWorkingDir . '/fakedir/file2.txt');
    }
}
