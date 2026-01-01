<?php

namespace BrianHenryIE\Strauss\Helpers;

use BrianHenryIE\Strauss\TestCase;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Helpers\FileSystem
 */
class FileSystemTest extends TestCase
{

    /**
     * Am I crazy or is there no easy way to get a file's attributes with Flysystem?
     * So I'm doing a directory listing then filtering to the file I want.
     * @throws FilesystemException
     */
    public function testFileAttributes(): void
    {
        $sut = new FileSystem(
            new \League\Flysystem\Filesystem(
                new LocalFilesystemAdapter('/'),
                [
                    Config::OPTION_DIRECTORY_VISIBILITY => 'public',
                ]
            ),
            __DIR__
        );

        $result = $sut->getAttributes(__FILE__);

        $this->assertInstanceOf(FileAttributes::class, $result);
    }

    public function testIsDirTrue(): void
    {
        $sut = new FileSystem(
            new \League\Flysystem\Filesystem(
                new LocalFilesystemAdapter('/'),
                [
                    Config::OPTION_DIRECTORY_VISIBILITY => 'public',
                ]
            ),
            __DIR__
        );

        $result = $sut->directoryExists(__DIR__);

        $this->assertTrue($result);
    }

    /**
     * Unix paths without leading slash should get one added.
     *
     * Flysystem's normalizer strips leading slashes. When paths are needed
     * for external tools (like Composer), they must be absolute.
     *
     * @covers ::makeAbsolute
     */
    public function testMakeAbsoluteAddsLeadingSlashForUnixPaths(): void
    {
        // Use a Unix-style working directory to test Unix behavior
        $unixWorkingDir = '/home/user/project/';
        
        $sut = new FileSystem(
            new \League\Flysystem\Filesystem(
                new LocalFilesystemAdapter('/'),
                [
                    Config::OPTION_DIRECTORY_VISIBILITY => 'public',
                ]
            ),
            $unixWorkingDir
        );

        // Simulate a path that's been through Flysystem's normalizer (no leading slash)
        $result = $sut->makeAbsolute('app/lib/composer.json');

        $this->assertSame('/app/lib/composer.json', $result);
    }

    /**
     * Windows paths with drive letter should NOT get a leading slash.
     *
     * @covers ::makeAbsolute
     */
    public function testMakeAbsolutePreservesWindowsDriveLetter(): void
    {
        $sut = new FileSystem(
            new \League\Flysystem\Filesystem(
                new LocalFilesystemAdapter('/'),
                [
                    Config::OPTION_DIRECTORY_VISIBILITY => 'public',
                ]
            ),
            __DIR__
        );

        $result = $sut->makeAbsolute('C:/Users/dev/project/composer.json');

        $this->assertSame('C:/Users/dev/project/composer.json', $result);
    }

    /**
     * Windows paths with lowercase drive letter should also be handled.
     *
     * @covers ::makeAbsolute
     */
    public function testMakeAbsolutePreservesLowercaseWindowsDriveLetter(): void
    {
        $sut = new FileSystem(
            new \League\Flysystem\Filesystem(
                new LocalFilesystemAdapter('/'),
                [
                    Config::OPTION_DIRECTORY_VISIBILITY => 'public',
                ]
            ),
            __DIR__
        );

        $result = $sut->makeAbsolute('d:/Work/project/composer.json');

        $this->assertSame('d:/Work/project/composer.json', $result);
    }

    /**
     * Paths with leading slash should have it restored after normalization.
     *
     * Flysystem's normalizer strips leading slashes. This test verifies that
     * makeAbsolute() correctly restores the leading slash for Unix absolute paths.
     *
     * @covers ::makeAbsolute
     */
    public function testMakeAbsoluteRestoresLeadingSlashAfterNormalization(): void
    {
        // Use a Unix-style working directory to test Unix behavior
        $unixWorkingDir = '/home/user/project/';
        
        $sut = new FileSystem(
            new \League\Flysystem\Filesystem(
                new LocalFilesystemAdapter('/'),
                [
                    Config::OPTION_DIRECTORY_VISIBILITY => 'public',
                ]
            ),
            $unixWorkingDir
        );

        // Input has leading slash, but Flysystem normalizer will strip it
        // makeAbsolute() should restore it
        $result = $sut->makeAbsolute('/already/absolute/path');

        $this->assertSame('/already/absolute/path', $result);
    }

    public function testIsDirFalse(): void
    {
        $sut = new FileSystem(
            new \League\Flysystem\Filesystem(
                new LocalFilesystemAdapter('/'),
                [
                    Config::OPTION_DIRECTORY_VISIBILITY => 'public',
                ]
            ),
            __DIR__
        );

        $result = $sut->directoryExists(__FILE__);

        $this->assertFalse($result);
    }
}
