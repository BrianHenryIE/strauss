<?php

namespace BrianHenryIE\Strauss\Helpers\Flysystem;

use BrianHenryIE\Strauss\TestCase;
use League\Flysystem\Config;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Helpers\Flysystem\SymlinkProtectFilesystemAdapter
 */
class SymlinkProtectFilesystemAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestsTempDir();
        mkdir($this->testsWorkingDir, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->testsWorkingDir));

        parent::tearDown();
    }

    protected function getSut(): SymlinkProtectFilesystemAdapter
    {
        return new SymlinkProtectFilesystemAdapter(
            FileSystem::getFsRoot($this->testsWorkingDir),
            null,
            null,
            $this->getLogger()
        );
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
        $sut = $this->getSut();

        $path = ltrim($this->testsWorkingDir, '/') . '/does-not-exist.txt';

        $sut->delete($path);

        $this->assertFalse($sut->fileExists($path));
    }

    /**
     * @covers ::delete
     */
    public function test_delete_existing_file(): void
    {
        $sut = $this->getSut();

        $path = ltrim($this->testsWorkingDir, '/') . '/exists.txt';
        $sut->write($path, 'contents', new Config());
        $this->assertTrue($sut->fileExists($path));

        $sut->delete($path);

        $this->assertFalse($sut->fileExists($path));
    }

    /**
     * @covers ::deleteDirectory
     */
    public function test_delete_missing_directory_does_not_throw(): void
    {
        $sut = $this->getSut();

        $path = ltrim($this->testsWorkingDir, '/') . '/does-not-exist';

        $sut->deleteDirectory($path);

        $this->assertFalse($sut->directoryExists($path));
    }

    /**
     * @covers ::deleteDirectory
     */
    public function test_delete_existing_directory(): void
    {
        $sut = $this->getSut();

        $path = ltrim($this->testsWorkingDir, '/') . '/exists';
        $sut->createDirectory($path, new Config());
        $sut->write($path . '/file.txt', 'contents', new Config());
        $this->assertTrue($sut->directoryExists($path));

        $sut->deleteDirectory($path);

        $this->assertFalse($sut->directoryExists($path));
    }
}
