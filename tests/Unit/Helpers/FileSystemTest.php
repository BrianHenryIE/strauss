<?php

namespace BrianHenryIE\Strauss\Helpers;

use BrianHenryIE\Strauss\TestCase;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Helpers\FileSystem
 */
class FileSystemTest extends TestCase
{

    /**
     * Am I crazy or is there no easy way to get a file's attributes with Flystem?
     * So I'm doing a directory listing then filtering to the file I want.
     */
    public function testFileAttributes(): void
    {
        $sut = new Filesystem(new LocalFilesystemAdapter('/'), [
                Config::OPTION_DIRECTORY_VISIBILITY => 'public',
            ]);


        $result = $sut->getAttributes(__FILE__);

        $this->assertInstanceOf(FileAttributes::class, $result);
    }

    public function testIsDirTrue()
    {
        $sut = new Filesystem(new LocalFilesystemAdapter('/'), [
                Config::OPTION_DIRECTORY_VISIBILITY => 'public',
            ]);

        $result = $sut->isDir(__DIR__);

        $this->assertTrue($result);
    }
    public function testIsDirFalse()
    {
        $sut = new Filesystem(new LocalFilesystemAdapter('/'), [
                Config::OPTION_DIRECTORY_VISIBILITY => 'public',
            ]);

        $result = $sut->isDir(__FILE__);

        $this->assertFalse($result);
    }
}
