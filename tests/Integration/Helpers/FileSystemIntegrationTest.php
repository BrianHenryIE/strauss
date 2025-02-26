<?php

namespace BrianHenryIE\Strauss\Helpers;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Helpers\FileSystem
 */
class FileSystemIntegrationTest extends IntegrationTestCase
{
    /**
     * @covers ::directoryExists
     */
    public function test_is_dir(): void
    {
        $fs = new Filesystem(
            new \League\Flysystem\Filesystem(
                new LocalFilesystemAdapter('/')
            ),
            $this->testsWorkingDir
        );

        $dir = $this->testsWorkingDir . 'dir';

        mkdir($dir);

        $this->assertTrue($fs->directoryExists($dir));
        $this->assertFalse($fs->directoryExists($this->testsWorkingDir . 'nonexistent'));
    }

    /**
     * @covers ::findAllFilesAbsolutePaths
     */
    public function test_find_all_files_absolute_paths(): void
    {
        $fs = new Filesystem(
            new \League\Flysystem\Filesystem(
                new LocalFilesystemAdapter('/')
            ),
            $this->testsWorkingDir
        );

        $dir = $this->testsWorkingDir . 'dir';

        mkdir($dir);

        $file1 = $dir . '/file1.php';
        $file2 = $dir . '/file2.php';

        mkdir($dir . '/subdir');

        $file3 = $dir . '/subdir/file3.php';

        file_put_contents($file1, 'file1');
        file_put_contents($file2, 'file2');
        file_put_contents($file3, 'file3');

        $files = $fs->findAllFilesAbsolutePaths([ $dir ]);

        $this->assertContains($file1, $files);
        $this->assertContains($file2, $files);
        $this->assertContains($file3, $files);
    }
}
