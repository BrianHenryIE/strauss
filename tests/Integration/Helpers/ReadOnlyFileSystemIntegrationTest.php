<?php

namespace BrianHenryIE\Strauss\Helpers;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Helpers\ReadOnlyFileSystem
 */
class ReadOnlyFileSystemIntegrationTest extends IntegrationTestCase
{

    // given a source file
    // and a destination target
    // when
    // I make changes to the "source" file
    // then
    // assert the target file was never truly written

    public function test_write(): void
    {
        $source = $this->testsWorkingDir . 'source.php';
        $this->getFileSystem()->write($source, 'source');

        $sut = new ReadOnlyFileSystem(new \League\Flysystem\FileSystem(new LocalFilesystemAdapter('/')));

        $target = $this->testsWorkingDir . 'target.php';

        $contents = $sut->read($source);

        $sut->write($target, $contents);

        $this->assertFileDoesNotExist($target);
    }

    // test writing a source file doesn't really write the file but does makes the changes available within

    //

    public function test_file_exists_true()
    {
        $source = $this->testsWorkingDir . 'source.php';

        assert(!file_exists($source));

        $sut = new ReadOnlyFileSystem(new \League\Flysystem\FileSystem(new LocalFilesystemAdapter('/')));
        
        $sut->write($source, 'source');

        assert(!file_exists($source));

        $this->assertTrue($sut->fileExists($source));
    }

    /**
     * When a file does actually exist, but is deleted in the readonly filesystem, file_exists should return false.
     */
    public function test_file_exists_false()
    {
        $source = $this->testsWorkingDir . 'source.php';
        $this->getFileSystem()->write($source, 'source');

        $sut = new ReadOnlyFileSystem(new \League\Flysystem\FileSystem(new LocalFilesystemAdapter('/')));

        $sut->delete($source);

        $this->assertFalse($sut->fileExists($source));
    }

    /**
     * @covers ::read
     */
    public function test_dry_run_deleted_file_throws_exception_on_read(): void
    {
        // given a file that was deleted in a dry run
        $source = $this->testsWorkingDir . 'source.php';
        $this->getFileSystem()->write($source, 'source');

        $sut = new ReadOnlyFileSystem(new \League\Flysystem\FileSystem(new LocalFilesystemAdapter('/')));
        $sut->delete($source);

        // when I try to read the file
        // then an exception should be thrown
        $this->expectException(\League\Flysystem\UnableToReadFile::class);
        $sut->read($source);
    }

    /**
     * Files deleted from the dry run filesystem should un-counted in the directory listing
     *
     * @covers ::listContents
     */
    public function testListContentsDeleteFile(): void
    {
        // Given a real file
        $aRealFile = $this->testsWorkingDir . 'file1.php';
        $this->getFileSystem()->write($aRealFile, 'file1');

        $sut = new ReadOnlyFileSystem(new \League\Flysystem\FileSystem(new LocalFilesystemAdapter('/')));
        assert(1 === count($sut->listContents($this->testsWorkingDir)->toArray()));

        // When it is deleted
        $sut->delete($aRealFile);

        // Then it should not be in the directory listing
        $this->assertCount(0, $sut->listContents($this->testsWorkingDir)->toArray());

        // And the file should still exist
        $this->assertFileExists($aRealFile);
    }

    /**
     * New files written to the dry run filesystem should be in the directory listing
     *
     * @covers ::listContents
     */
    public function testListContentsAddFile(): void
    {
        // Given a real file
        $aRealFile = $this->testsWorkingDir . 'file1.php';
        $this->getFileSystem()->write($aRealFile, 'file1');

        $sut = new ReadOnlyFileSystem(new \League\Flysystem\FileSystem(new LocalFilesystemAdapter('/')));
        assert(1 === count($sut->listContents($this->testsWorkingDir)->toArray()));

        $file2Path = $this->testsWorkingDir . 'file2.php';
        // And a new file
        $sut->write($file2Path, '<?php whatever ?>');

        // Then both should be in the directory listing
        $this->assertCount(2, $sut->listContents($this->testsWorkingDir)->toArray());

        // And the file should not actually exist
        $this->assertFileDoesNotExist($file2Path);
    }

    public function test_copy():void
    {
        $source = $this->testsWorkingDir . 'source.php';
        $contents = 'source';
        $this->getFileSystem()->write($source, $contents);

        $sut = new ReadOnlyFileSystem(new \League\Flysystem\FileSystem(new LocalFilesystemAdapter('/')));

        $destination = $this->testsWorkingDir . 'destination.php';

        $sut->copy($source, $destination);

        $this->assertEquals($contents, $sut->read($destination));

        $this->assertFileDoesNotExist($destination);
    }

    /**
     * @covers ::directoryExists
     */
    public function testDirectoryExists(): void
    {
        $newDir = $this->testsWorkingDir . 'dir1';
        mkdir($newDir);

        $sut = new ReadOnlyFileSystem(new \League\Flysystem\FileSystem(new LocalFilesystemAdapter('/')));

        $this->assertTrue($sut->directoryExists($newDir));
    }

    /**
     * @covers ::directoryExists
     */
    public function testDirectoryExistsDelete(): void
    {
        $newDir = $this->testsWorkingDir . 'dir1';
        mkdir($newDir);

        $sut = new ReadOnlyFileSystem(new \League\Flysystem\FileSystem(new LocalFilesystemAdapter('/')));

        $sut->deleteDirectory($newDir);

        $this->assertDirectoryExists($newDir);
        $this->assertFalse($sut->directoryExists($newDir));
    }

    /**
     * @covers ::directoryExists
     */
    public function testDirectoryExistsPhantomDir(): void
    {
        $newDir = $this->testsWorkingDir . 'dir1';

        $sut = new ReadOnlyFileSystem(new \League\Flysystem\FileSystem(new LocalFilesystemAdapter('/')));

        $sut->createDirectory($newDir);

        $this->assertDirectoryDoesNotExist($newDir);
        $this->assertTrue($sut->directoryExists($newDir));
    }
}
