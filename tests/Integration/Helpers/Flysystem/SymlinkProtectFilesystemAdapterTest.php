<?php
/**
 * Delete operations to symlinks should be changed to unlink.
 */

namespace BrianHenryIE\Strauss\Helpers\Flysystem;

use BrianHenryIE\Strauss\IntegrationTestCase;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToWriteFile;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Helpers\Flysystem\SymlinkProtectFilesystemAdapter
 */
class SymlinkProtectFilesystemAdapterTest extends IntegrationTestCase
{
    protected FileSystem $filesystemThrow;
    protected FileSystem $filesystemWarn;

    public function setUp(): void
    {
        parent::setUp();

        mkdir($this->testsWorkingDir . '/realdir');
        mkdir($this->testsWorkingDir . '/realdir/subdir');
        // aka /fakedir/subdir
        file_put_contents($this->testsWorkingDir . '/realdir/file.txt', 'test');
        // aka /fakedir/file.txt
        symlink($this->testsWorkingDir . '/realdir', $this->testsWorkingDir . '/fakedir');

        $sutThrow = new SymlinkProtectFilesystemAdapter(
            FileSystem::getFsRoot($this->testsWorkingDir),
            null,
            null,
            $this->getTestLogger(),
            null,
            LOCK_EX,
            SymlinkProtectFilesystemAdapter::THROW_LINKS,
        );

        $sutWarn = new SymlinkProtectFilesystemAdapter(
            FileSystem::getFsRoot($this->testsWorkingDir),
            null,
            null,
            $this->getTestLogger(),
            null,
            LOCK_EX,
            SymlinkProtectFilesystemAdapter::WARN_LINKS,
        );

        $this->filesystemThrow = new FileSystem(
            $sutThrow,
            [],
            FileSystem::makePathNormalizer($this->testsWorkingDir),
            null,
            $this->testsWorkingDir,
        );
        $this->filesystemWarn = new FileSystem(
            $sutWarn,
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
        $this->filesystemWarn->deleteDirectory($this->testsWorkingDir . '/fakedir');

        $this->assertTrue($this->getTestLogger()->hasNoticeRecords());

        $this->assertDirectoryDoesNotExist($this->testsWorkingDir . '/fakedir');
    }

    /**
     * When deleting a directory inside a symlinked directory
     * An UnableToDeleteDirectory exception should be thrown
     * And the symlink should not be deleted
     */
    public function test_delete_directory_in_symlinked_directory(): void
    {
        $this->expectException(UnableToDeleteDirectory::class);

        $this->filesystemThrow->deleteDirectory($this->testsWorkingDir . '/fakedir/subdir');
    }

    /**
     * When deleting a file inside a symlinked directory
     * An UnableToDeleteFile exception should be thrown
     * And the target file should not be deleted
     */
    public function test_delete_file_in_symlinked_directory(): void
    {
        $this->expectException(UnableToDeleteFile::class);

        $this->filesystemThrow->delete($this->testsWorkingDir . '/fakedir/file.txt');
    }

    /**
     * When writing a file inside a symlinked directory
     * An UnableToWriteFile exception should be thrown
     * And the target file should not be written to
     */
    public function test_write_file_in_symlinked_directory(): void
    {
        $this->expectException(UnableToWriteFile::class);

        $this->filesystemThrow->write($this->testsWorkingDir . '/fakedir/file2.txt', 'test');
    }

    /**
     * A sibling directory whose name starts with a symlink's name should not be treated as inside the symlink.
     * E.g. symlink "lib" should not block writes to "library".
     *
     * Regression test for false-positive in str_starts_with() prefix check.
     */
    public function test_sibling_directory_not_treated_as_inside_symlink(): void
    {
        // Set up: "lib" is a symlink, "library" is a real sibling directory.
        mkdir($this->testsWorkingDir . '/reallib');
        symlink($this->testsWorkingDir . '/reallib', $this->testsWorkingDir . '/lib');
        mkdir($this->testsWorkingDir . '/library');

        // Trigger symlink detection so "lib" is recorded in symlinkRealPaths cache.
        try {
            $this->filesystemThrow->write($this->testsWorkingDir . '/lib/blocked.txt', 'blocked');
            $this->fail('Expected UnableToWriteFile for symlinked path');
        } catch (UnableToWriteFile $e) {
            // expected
        }

        // Writing to sibling "library" must not be blocked by the "lib" symlink record.
        $this->filesystemThrow->write($this->testsWorkingDir . '/library/allowed.txt', 'allowed');

        $this->assertFileExists($this->testsWorkingDir . '/library/allowed.txt');
        $this->assertFileDoesNotExist($this->testsWorkingDir . '/reallib/blocked.txt');
    }

    /**
     * Flysystem's `LocalFilesystemAdapter::delete()` is a no-op for missing files; this adapter must match.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/331
     *
     * @covers ::delete
     */
    public function test_delete_missing_file_does_not_throw(): void
    {
        $path = $this->testsWorkingDir . '/does-not-exist.txt';

        $this->filesystemThrow->delete($path);

        $this->assertFalse($this->filesystemThrow->fileExists($path));
    }

    /**
     * @covers ::delete
     */
    public function test_delete_existing_file(): void
    {
        $path = $this->testsWorkingDir . '/exists.txt';
        $this->filesystemThrow->write($path, 'contents');
        $this->assertTrue($this->filesystemThrow->fileExists($path));

        $this->filesystemThrow->delete($path);

        $this->assertFalse($this->filesystemThrow->fileExists($path));
    }

    /**
     * @see https://github.com/BrianHenryIE/strauss/issues/331
     *
     * @covers ::deleteDirectory
     */
    public function test_delete_missing_directory_does_not_throw(): void
    {
        $path = $this->testsWorkingDir . '/does-not-exist';

        $this->filesystemThrow->deleteDirectory($path);

        $this->assertFalse($this->filesystemThrow->directoryExists($path));
    }

    /**
     * @covers ::deleteDirectory
     */
    public function test_delete_existing_directory(): void
    {
        $path = $this->testsWorkingDir . '/exists';
        $this->filesystemThrow->createDirectory($path);
        $this->filesystemThrow->write($path . '/file.txt', 'contents');
        $this->assertTrue($this->filesystemThrow->directoryExists($path));

        $this->filesystemThrow->deleteDirectory($path);

        $this->assertFalse($this->filesystemThrow->directoryExists($path));
    }
}
