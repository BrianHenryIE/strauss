<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\Strauss\Config\CopierConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Helpers\Log\LogPlaceholderSubstituter;
use BrianHenryIE\Strauss\Helpers\Log\RelativeFilepathLogger;
use BrianHenryIE\Strauss\TestCase;

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
        $filesystem = $this->getInMemoryFileSystem();

        $sourceDir = 'mem://source';
        $targetDir = 'mem://target';

        $filepath = $sourceDir . '/file.php';
        $filesystem->write($filepath, 'test');

        $file = new File($filepath);
        $file->setAbsoluteTargetPath($targetDir . '/file.php');

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $config = \Mockery::mock(CopierConfigInterface::class);

        $colorLogger = new ColorLogger();
        $logger = new RelativeFilepathLogger(
            $filesystem,
            new LogPlaceholderSubstituter(
                $colorLogger
            )
        );

        $sut = new Copier($discoveredFiles, $config, $filesystem, $logger);
        $sut->copy();

        $this->assertTrue($filesystem->fileExists($targetDir . '/file.php'));
        $this->assertEquals('test', $filesystem->read($targetDir . '/file.php'));

        $this->assertTrue($colorLogger->hasInfo('Copying file to target/file.php'));
    }

    /**
     * @covers ::__construct
     * @covers ::copy
     */
    public function test_file_is_skipped(): void
    {
        $filesystem = $this->getInMemoryFileSystem();

        $sourceDir = 'mem://source';
        $targetDir = 'mem://target';

        $filepath = $sourceDir . '/file.php';
        $filesystem->write($filepath, 'test');

        $file = new File($filepath);
        $file->setAbsoluteTargetPath($targetDir . '/file.php');
        $file->setDoCopy(false);

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $config = \Mockery::mock(CopierConfigInterface::class);

        $sut = new Copier($discoveredFiles, $config, $filesystem, $this->getLogger());
        $sut->copy();

        $this->assertFalse($filesystem->fileExists($targetDir . '/file.php'));

        $this->assertTrue($this->getTestLogger()->hasDebug('Skipping source/file.php'));
    }

    /**
     * @covers ::__construct
     * @covers ::copy
     */
    public function test_file_not_found(): void
    {
        $filesystem = $this->getInMemoryFileSystem();

        $sourceDir = 'mem://source';
        $targetDir = 'mem://target';

        $filepath = $sourceDir . '/file.php';

        $file = new File($filepath);
        $file->setAbsoluteTargetPath($targetDir . '/file.php');

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $config = \Mockery::mock(CopierConfigInterface::class);

        $sut = new Copier($discoveredFiles, $config, $filesystem, $this->getLogger());
        $sut->copy();

        $this->assertTrue($this->getTestLogger()->hasWarning('Expected file not found: source/file.php'));
    }

    public function testCreateDirectory(): void
    {
        $filesystem = $this->getInMemoryFileSystem();

        $sourceDir = 'mem://source';
        $targetDir = 'mem://target';

        $filesystem->createDirectory($sourceDir);

        $file = new File($sourceDir);
        $file->setAbsoluteTargetPath($targetDir);

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $config = \Mockery::mock(CopierConfigInterface::class);

        $sut = new Copier($discoveredFiles, $config, $filesystem, $this->getLogger());
        $sut->copy();

        $this->assertTrue($filesystem->directoryExists($targetDir));

        $this->assertTrue($this->getTestLogger()->hasInfo('Creating directory at target'));
    }
}
