<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Config\AutoloadConfigInterace;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\TestCase;
use Elazar\Flystream\FilesystemRegistry;
use League\Flysystem\Config;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Psr\Log\Test\TestLogger;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\Autoload
 */
class AutoloadTest extends TestCase
{

    protected function tearDown(): void
    {
        parent::tearDown();

        /** @var FilesystemRegistry $registry */
        $registry = \Elazar\Flystream\ServiceLocator::get(\Elazar\Flystream\FilesystemRegistry::class);

        $registry->unregister('mem');
    }

    /**
     * @covers ::generateClassmap
     */
    public function testGenerateClassmap(): void
    {
        $this->markTestSkipped('TODO: move to VendorComposerAutoloadTest');

        $config = \Mockery::mock(AutoloadConfigInterace::class);
        $config->expects('getTargetDirectory')->andReturn('vendor-prefixed')->once();
        $config->expects('getVendorDirectory')->andReturn('vendor')->once();
        $config->expects('isClassmapOutput')->andReturnTrue()->once();
        $config->expects('isDryRun')->andReturnTrue()->once();

        $absoluteWorkingDir = '/';
        $discoveredFilesAutoloaders = array();
        $filesystem = $this->getFileSystem();
        $logger = new TestLogger();

        $filesystem->write(
            'vendor-prefixed/psr/log/Psr/Log/Test/TestLogger.php',
            file_get_contents(getcwd() . '/vendor/psr/log/Psr/Log/Test/TestLogger.php')
        );

        $sut = new Autoload(
            $config,
            $discoveredFilesAutoloaders,
            $filesystem,
            $logger
        );

        $sut->generate();

        $this->assertTrue($filesystem->fileExists('vendor-prefixed/autoload-classmap.php'));

        $autoloadClassmap = $filesystem->read('vendor-prefixed/autoload-classmap.php');

        $this->assertStringContainsString("'Psr\Log\Test\TestLogger' => \$strauss_src . '/psr/log/Psr/Log/Test/TestLogger.php',". PHP_EOL, $autoloadClassmap);
    }

    /**
     * @see https://github.com/BrianHenryIE/strauss/issues/143#issuecomment-2648129475
     *
     * @covers ::generateClassmap
     */
    public function testGenerateClassmapParentRelativeDir(): void
    {
        $this->markTestSkipped('TODO: move to VendorComposerAutoloadTest');

        $config = \Mockery::mock(AutoloadConfigInterace::class);
        $config->expects('getTargetDirectory')->andReturn('../vendor-prefixed')->once();
        $config->expects('getVendorDirectory')->andReturn('../vendor')->once();
        $config->expects('isClassmapOutput')->andReturnTrue()->once();
        $config->expects('isDryRun')->andReturnTrue()->once();

        $absoluteWorkingDir = '/path/to/myproject/build/';
        $discoveredFilesAutoloaders = array();
        $filesystem = $this->getFileSystem();
        $logger = new TestLogger();

        $filesystem->write(
            'path/to/myproject/vendor-prefixed/psr/log/Psr/Log/Test/TestLogger.php',
            file_get_contents(getcwd() . '/vendor/psr/log/Psr/Log/Test/TestLogger.php')
        );

        $sut = new Autoload(
            $config,
            $discoveredFilesAutoloaders,
            $filesystem,
            $logger
        );

        $sut->generate();

        $this->assertTrue($filesystem->fileExists('path/to/myproject/vendor-prefixed/autoload-classmap.php'));

        $autoloadClassmap = $filesystem->read('path/to/myproject/vendor-prefixed/autoload-classmap.php');

        $this->assertStringContainsString("'Psr\Log\Test\TestLogger' => \$strauss_src . '/psr/log/Psr/Log/Test/TestLogger.php',". PHP_EOL, $autoloadClassmap);
    }

    /**
     * @covers ::generateFilesAutoloader
     */
    public function testGenerateFilesAutoloader(): void
    {
        $this->markTestSkipped('TODO: move to VendorComposerAutoloadTest');

        $config = \Mockery::mock(AutoloadConfigInterace::class);
        $config->expects('getTargetDirectory')->andReturn('vendor-prefixed')->once();
        $config->expects('getVendorDirectory')->andReturn('vendor')->once();
        $config->expects('isClassmapOutput')->andReturnTrue()->once();
        $config->expects('isDryRun')->andReturnTrue()->once();

        $absoluteWorkingDir = '/';
        $discoveredFilesAutoloaders = array (
            'rubix/tensor' =>
                array (
                    0 => 'src/constants.php',
                ),
        );
        $filesystem = $this->getFileSystem();
        $logger = new TestLogger();

        $filesystem->write(
            'vendor-prefixed/rubix/tensor/src/constants.php',
            '<?php '
        );

        $sut = new Autoload(
            $config,
            $discoveredFilesAutoloaders,
            $filesystem,
            $logger
        );

        $sut->generate();

        $this->assertTrue($filesystem->fileExists('vendor-prefixed/autoload-files.php'));

        $autoloadClassmap = $filesystem->read('vendor-prefixed/autoload-files.php');

        $this->assertStringContainsString("require_once __DIR__ . '/rubix/tensor/src/constants.php';" . PHP_EOL, $autoloadClassmap);
    }
}
