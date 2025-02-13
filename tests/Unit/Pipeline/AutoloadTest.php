<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use Elazar\Flystream\FilesystemRegistry;
use League\Flysystem\Config;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Psr\Log\Test\TestLogger;

/**
 * @coversDefaultClass Autoload
 */
class AutoloadTest extends \PHPUnit\Framework\TestCase
{

    protected function getStreamWrappedFilesystem()
    {
        $inMemoryFilesystem = new InMemoryFilesystemAdapter();

        $filesystem = new Filesystem(
            new \League\Flysystem\Filesystem(
                $inMemoryFilesystem,
                [
                    Config::OPTION_DIRECTORY_VISIBILITY => 'public',
                ]
            )
        );

        /** @var FilesystemRegistry $registry */
        $registry = \Elazar\Flystream\ServiceLocator::get(\Elazar\Flystream\FilesystemRegistry::class);

        // Register a file stream mem:// to handle file operations by third party libraries.
        // This exception handling probably doesn't matter in real life but does in unit tests.
        try {
            $registry->get('mem');
        } catch (\Exception $e) {
            $registry->register('mem', $filesystem);
        }

        return $filesystem;
    }

    /**
     * @covers ::generateClassmap
     */
    public function testGenerateClassmap(): void
    {
        $config = \Mockery::mock(StraussConfig::class);
        $config->expects('getTargetDirectory')->andReturn('vendor-prefixed')->once();
        $config->expects('getVendorDirectory')->andReturn('vendor')->once();
        $config->expects('isClassmapOutput')->andReturnTrue()->once();
        $config->expects('isDryRun')->andReturnTrue()->once();

        $absoluteWorkingDir = '/';
        $discoveredFilesAutoloaders = array();
        $filesystem = $this->getStreamWrappedFilesystem();
        $logger = new TestLogger();

        $filesystem->write(
            'vendor-prefixed/psr/log/Psr/Log/Test/TestLogger.php',
            file_get_contents(getcwd() . '/vendor/psr/log/Psr/Log/Test/TestLogger.php')
        );

        $sut = new Autoload(
            $config,
            $absoluteWorkingDir,
            $discoveredFilesAutoloaders,
            $filesystem,
            $logger
        );

        $sut->generate();

        $this->assertTrue($filesystem->fileExists('vendor-prefixed/autoload-classmap.php'));

        $autoloadClassmap = $filesystem->read('vendor-prefixed/autoload-classmap.php');

        $this->assertStringContainsString("'Psr\Log\Test\TestLogger' => \$strauss_src . '/psr/log/Psr/Log/Test/TestLogger.php',". PHP_EOL, $autoloadClassmap);
    }
}
