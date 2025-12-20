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
