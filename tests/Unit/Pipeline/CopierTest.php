<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Config\CopierConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\TestCase;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\Copier
 */
class CopierTest extends TestCase
{
    /**
     * @covers ::__construct
     * @covers ::copy
     */
    public function test_file_is_copied(): void
    {
        $filesystem = $this->getFileSystem();

        $sourceDir = 'source';
        $targetDir = 'target';

        $filepath = $sourceDir . '/file.php';
        $filesystem->write($filepath, 'test');

        $file = new File(
            $filepath,
            '
            file.php',
            $targetDir . '/file.php'
        );
        $file->setTargetAbsolutePath($targetDir . '/file.php');

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $config = \Mockery::mock(CopierConfigInterface::class);

        $sut = new Copier($discoveredFiles, $config, $filesystem, $this->getLogger());
        $sut->copy();

        $this->assertTrue($filesystem->fileExists($targetDir . '/file.php'));
        $this->assertEquals('test', $filesystem->read($targetDir . '/file.php'));

        $this->assertTrue($this->getTestLogger()->hasInfoThatContains('Copying file to'));
    }

    /**
     * @covers ::__construct
     * @covers ::copy
     */
    public function test_file_is_skipped(): void
    {
        $filesystem = $this->getFileSystem();

//        $sourceDir = 'mem://source';
//        $targetDir = 'mem://target';
        $sourceDir = $this->testsWorkingDir . '/source';
        $targetDir = $this->testsWorkingDir . '/target';

        $filepath = $sourceDir . '/file.php';
        $filesystem->write($filepath, 'test');

        $file = new File(
            $filepath,
            'file.php',
            $targetDir . '/file.php'
        );
        $file->setTargetAbsolutePath($targetDir . '/file.php');
        $file->setDoCopy(false);

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $config = \Mockery::mock(CopierConfigInterface::class);

        $sut = new Copier($discoveredFiles, $config, $filesystem, $this->getLogger());
        $sut->copy();

        $this->assertFalse($filesystem->fileExists($targetDir . '/file.php'));

        $this->assertTrue($this->getTestLogger()->hasDebugThatContains('Skipping'));
    }

    /**
     * @covers ::__construct
     * @covers ::copy
     */
    public function test_file_not_found(): void
    {
        $this->expectWarningLogs();

        $filesystem = $this->getFileSystem();

//        $sourceDir = 'mem://source';
//        $targetDir = 'mem://target';
        $sourceDir = $this->testsWorkingDir . '/source';
        $targetDir = $this->testsWorkingDir . '/target';

        $filepath = $sourceDir . '/file.php';

        $file = Mockery::mock(File::class);
        $file->expects()->isDoCopy()->andReturnTrue();
        $file->expects()->getSourcePath()->andReturn($filepath)->atleast()->Once();
        $file->expects()->getTargetAbsolutePath()->andReturn($targetDir . '/file.php');
        $file->expects()->setDoPrefix(false);

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $config = \Mockery::mock(CopierConfigInterface::class);

        $sut = new Copier($discoveredFiles, $config, $filesystem, $this->getLogger());
        $sut->copy();

        $this->assertTrue($this->getTestLogger()->hasWarningThatContains('Expected file not found:'));
    }

    public function testCreateDirectory(): void
    {
        $filesystem = $this->getFileSystem();

        $sourceDir = 'source';
        $targetDir = 'target';

        $filesystem->createDirectory($sourceDir);

        $file = new File(
            $sourceDir. '/file.php',
            'file.php',
            $targetDir . '/file.php'
        );

        $filesystem->write($sourceDir . '/file.php', 'test');

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $config = \Mockery::mock(CopierConfigInterface::class);

        $sut = new Copier($discoveredFiles, $config, $filesystem, $this->getTestLogger());
        $sut->copy();

        $this->assertTrue($filesystem->directoryExists($targetDir));
    }

    /**
     * A file the writer says it wrote is not copied; one it declines is copied as usual.
     *
     * @covers ::setFileWriter
     * @covers ::copy
     */
    public function test_file_writer_decides_whether_a_file_is_copied(): void
    {
        $filesystem = $this->getFileSystem();

        $filesystem->write('source/written.php', 'written source');
        $filesystem->write('source/declined.php', 'declined source');
        $filesystem->write('source/skipped.php', 'skipped source');
        $filesystem->createDirectory('source/directory');

        $written = new File('source/written.php', 'written.php', 'target/written.php');
        $declined = new File('source/declined.php', 'declined.php', 'target/declined.php');
        $skipped = new File('source/skipped.php', 'skipped.php', 'target/skipped.php');
        $skipped->setDoCopy(false);
        $directory = new File('source/directory', 'directory', 'target/directory');
        $missing = new File('source/missing.php', 'missing.php', 'target/missing.php');

        $config = \Mockery::mock(CopierConfigInterface::class);

        $offered = [];

        $this->expectWarningLogs();

        $sut = new Copier(
            new DiscoveredFiles([$written, $declined, $skipped, $directory, $missing]),
            $config,
            $filesystem,
            $this->getLogger()
        );
        $sut->setFileWriter(function (File $file) use (&$offered, $filesystem): bool {
            $offered[] = $file->getSourcePath();
            if ('source/written.php' !== $file->getSourcePath()) {
                return false;
            }
            $filesystem->write($file->getTargetAbsolutePath(), 'written by the writer');
            return true;
        });
        $sut->copy();

        // Only existing files which are to be copied are offered to the writer.
        $this->assertSame(['source/written.php', 'source/declined.php'], $offered);

        $this->assertSame('written by the writer', $filesystem->read('target/written.php'));
        $this->assertSame('declined source', $filesystem->read('target/declined.php'));
        $this->assertFalse($filesystem->fileExists('target/skipped.php'));
        $this->assertTrue($filesystem->directoryExists('target/directory'));
        $this->assertFalse($filesystem->fileExists('target/missing.php'));
    }

    /**
     * @covers ::setFileWriter
     * @covers ::copy
     */
    public function test_files_are_copied_when_the_file_writer_is_removed(): void
    {
        $filesystem = $this->getFileSystem();
        $filesystem->write('source/file.php', 'source');

        $file = new File('source/file.php', 'file.php', 'target/file.php');

        $sut = new Copier(
            new DiscoveredFiles([$file]),
            \Mockery::mock(CopierConfigInterface::class),
            $filesystem,
            $this->getLogger()
        );
        $sut->setFileWriter(function (File $file): bool {
            self::fail('The writer was removed.');
        });
        $sut->setFileWriter(null);
        $sut->copy();

        $this->assertSame('source', $filesystem->read('target/file.php'));
    }
}
