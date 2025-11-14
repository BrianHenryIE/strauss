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
        $filesystem = $this->getReadOnlyFileSystem();

        $sourceDir = 'mem://source';
        $targetDir = 'mem://target';

        $filepath = $sourceDir . '/file.php';
        $filesystem->write($filepath, 'test');

        $file = new File($filepath, 'file.php');
        $file->setAbsoluteTargetPath($targetDir . '/file.php');

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
        $filesystem = $this->getReadOnlyFileSystem();

        $sourceDir = 'mem://source';
        $targetDir = 'mem://target';

        $filepath = $sourceDir . '/file.php';
        $filesystem->write($filepath, 'test');

        $file = new File($filepath, 'file.php');
        $file->setAbsoluteTargetPath($targetDir . '/file.php');
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
        $filesystem = $this->getReadOnlyFileSystem();

        $sourceDir = 'mem://source';
        $targetDir = 'mem://target';

        $filepath = $sourceDir . '/file.php';

        $file = Mockery::mock(File::class);
        $file->expects()->isDoCopy()->andReturnTrue();
        $file->expects()->getSourcePath()->andReturn($filepath)->atleast()->Once();
        $file->expects()->getAbsoluteTargetPath()->andReturn($targetDir . '/file.php');
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
        $filesystem = $this->getReadOnlyFileSystem();

        $sourceDir = 'mem://source';
        $targetDir = 'mem://target';

        $filesystem->createDirectory($sourceDir);

        $file = new File($sourceDir, 'file.php');
        $file->setAbsoluteTargetPath($targetDir);

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $config = \Mockery::mock(CopierConfigInterface::class);

        $sut = new Copier($discoveredFiles, $config, $filesystem, $this->getLogger());
        $sut->copy();

        $this->assertTrue($filesystem->directoryExists($targetDir));

        $this->assertTrue($this->getTestLogger()->hasInfoThatContains('Creating directory at'));
    }
}
