<?php

namespace BrianHenryIE\Strauss\Pipeline\Autoload;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\Autoload\Psr0
 */
class Psr0Test extends TestCase
{
    /**
     * @covers ::setTargetDirectory
     */
    public function test_non_file_with_dependency_is_skipped(): void
    {
        $file = Mockery::mock(FileBase::class);
        $file->allows('getSourcePath')->andReturn('vendor/pimple/pimple/src/Pimple/Container.php');
        $file->shouldNotReceive('setTargetAbsolutePath');

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $sut = new Psr0($this->getInMemoryFileSystem(), $this->getLogger());
        $sut->setTargetDirectory($discoveredFiles);
    }

    /**
     * @covers ::setTargetDirectory
     */
    public function test_non_php_file_is_skipped(): void
    {
        $file = Mockery::mock(FileWithDependency::class);
        $file->allows('getSourcePath')->andReturn('vendor/pimple/pimple/src/Pimple/Container.php');
        $file->expects('isPhpFile')->andReturnFalse();
        $file->shouldNotReceive('getDependency');
        $file->shouldNotReceive('setTargetAbsolutePath');

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $sut = new Psr0($this->getInMemoryFileSystem(), $this->getLogger());
        $sut->setTargetDirectory($discoveredFiles);
    }

    /**
     * @covers ::setTargetDirectory
     */
    public function test_file_without_psr0_autoload_is_skipped(): void
    {
        $dependency = Mockery::mock(ComposerPackage::class);
        $dependency->expects('getAutoload')->andReturn(['psr-4' => ['Pimple\\' => 'src/']]);

        $file = Mockery::mock(FileWithDependency::class);
        $file->allows('getSourcePath')->andReturn('vendor/pimple/pimple/src/Pimple/Container.php');
        $file->expects('isPhpFile')->andReturnTrue();
        $file->expects('getDependency')->andReturn($dependency);
        $file->shouldNotReceive('getNamespaces');
        $file->shouldNotReceive('setTargetAbsolutePath');

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $sut = new Psr0($this->getInMemoryFileSystem(), $this->getLogger());
        $sut->setTargetDirectory($discoveredFiles);
    }

    /**
     * @covers ::setTargetDirectory
     */
    public function test_psr0_target_directory_is_updated(): void
    {
        $namespaceSymbol = new NamespaceSymbol('Pimple');
        $namespaceSymbol->setLocalReplacement('BrianHenryIE\Strauss\Pimple');
        $namespaces = new DiscoveredSymbols([$namespaceSymbol]);

        $dependency = Mockery::mock(ComposerPackage::class);
        $dependency->expects('getAutoload')->andReturn(['psr-0' => ['Pimple' => 'src/']]);

        $file = Mockery::mock(FileWithDependency::class);
        $file->allows('getSourcePath')->andReturn('vendor/pimple/pimple/src/Pimple/Container.php');
        $file->expects('isPhpFile')->andReturnTrue();
        $file->expects('getDependency')->andReturn($dependency);
        $file->expects('getNamespaces')->andReturn($namespaces);
        $file->expects('getPackageRelativePath')->andReturn('src/Pimple/Container.php');
        $file->expects('getTargetAbsolutePath')->andReturn('vendor-prefixed/pimple/pimple/src/Pimple/Container.php');
        $file->expects('setTargetAbsolutePath')
            ->with('vendor-prefixed/pimple/pimple/src/BrianHenryIE/Strauss/Pimple/Container.php')
            ->once();

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $sut = new Psr0($this->getInMemoryFileSystem(), $this->getLogger());
        $sut->setTargetDirectory($discoveredFiles);

        $this->addToAssertionCount(1); // Mockery verifies setTargetAbsolutePath was called with expected path
    }

    /**
     * @covers ::setTargetDirectory
     */
    public function test_multiple_namespaces_logs_warning(): void
    {
        $this->expectWarningLogs();

        $ns1 = new NamespaceSymbol('Pimple');
        $ns1->setLocalReplacement('BrianHenryIE\Strauss\Pimple');
        $ns2 = new NamespaceSymbol('Pimple\ServiceIterator');
        $ns2->setLocalReplacement('BrianHenryIE\Strauss\Pimple\ServiceIterator');
        $namespaces = new DiscoveredSymbols([$ns1, $ns2]);

        $dependency = Mockery::mock(ComposerPackage::class);
        $dependency->expects('getAutoload')->andReturn(['psr-0' => ['Pimple' => 'src/']]);

        $file = Mockery::mock(FileWithDependency::class);
        $file->allows('getSourcePath')->andReturn('vendor/pimple/pimple/src/Pimple/ServiceIterator.php');
        $file->expects('isPhpFile')->andReturnTrue();
        $file->expects('getDependency')->andReturn($dependency);
        $file->expects('getNamespaces')->andReturn($namespaces);
        $file->expects('getPackageRelativePath')->andReturn('src/Pimple/ServiceIterator.php');
        $file->expects('getTargetAbsolutePath')->andReturn('vendor-prefixed/pimple/pimple/src/Pimple/ServiceIterator.php');
        $file->expects('setTargetAbsolutePath')->once();

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $sut = new Psr0($this->getInMemoryFileSystem(), $this->getLogger());
        $sut->setTargetDirectory($discoveredFiles);

        $this->assertTrue($this->getTestLogger()->hasWarning('More than one namespace in PSR-0 file.'));
    }
}
