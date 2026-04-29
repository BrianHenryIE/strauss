<?php

namespace BrianHenryIE\Strauss\Pipeline\Autoload;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\DeepDependenciesCollection;
use BrianHenryIE\Strauss\Composer\DependenciesCollection;
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
        $sut->setTargetDirectory($dependencies, $discoveredFiles, $discoveredSymbols);
    }

    /**
     * TODO: non-PHP files in directories that are being renamed should be moved too, the classes might need them.
     *
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
        $sut->setTargetDirectory($dependencies, $discoveredFiles, $discoveredSymbols);
    }

    /**
     * @covers ::setTargetDirectory
     */
    public function test_dependency_without_psr0_autoload_is_skipped(): void
    {
        $sut = new Psr0($this->getInMemoryFileSystem(), $this->getLogger());

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredSymbols = Mockery::mock(DiscoveredSymbols::class);

        $dependency = Mockery::mock(ComposerPackage::class);
        $dependency->allows('getDependencies')->andReturn([]);
        $dependency->allows('getPackageName')->andReturn('my/package');

        $flatDependencyTree = new DeepDependenciesCollection([$dependency]);

        $dependency->expects('hasPsr0')->andReturnFalse();

        $sut->setTargetDirectory($flatDependencyTree, $discoveredFiles, $discoveredSymbols);
    }

    /**
     * @covers ::setTargetDirectory
     */
    public function test_psr0_target_directory_is_updated(): void
    {
        $namespaceSymbol = new NamespaceSymbol('Pimple');
        $namespaceSymbol->setLocalReplacement('BrianHenryIE\Strauss\Pimple');
        $discoveredSymbols = new DiscoveredSymbols([$namespaceSymbol]);

        $dependency = Mockery::mock(ComposerPackage::class);
        $dependency->expects('getAutoload')->andReturn(['psr-0' => ['Pimple' => 'src/']]);
        $dependency->expects('hasPsr0')->andReturnTrue();
        $dependency->allows('getPackageName')->andReturn('pimple/pimple');

        $file = Mockery::mock(FileWithDependency::class);
        $file->allows('getSourcePath')->andReturn('project/vendor/pimple/pimple/src/Pimple/Container.php');
        $file->expects('getPackageRelativePath')->andReturn('src/Pimple/Container.php');
        $file->expects('getTargetAbsolutePath')->andReturn('project/vendor-prefixed/pimple/pimple/src/Pimple/Container.php');

        $file->expects('setTargetAbsolutePath')
            ->with('project/vendor-prefixed/pimple/pimple/src/BrianHenryIE/Strauss/Pimple/Container.php')
            ->once();

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $dependency->expects('getFiles')->andReturn($discoveredFiles);

        $dependencies = new DependenciesCollection([$dependency]);

        $sut = new Psr0($this->getInMemoryFileSystem(), $this->getLogger());
        $sut->setTargetDirectory($dependencies, $discoveredFiles, $discoveredSymbols);
    }

    /**
     * @covers ::setTargetDirectory
     */
    public function test_multiple_namespaces_logs_warning(): void
    {
        $this->markTestIncomplete('outdated after refactoring for underscores in classnames');

        $this->expectWarningLogs();

        $ns1 = new NamespaceSymbol('Pimple');
        $ns1->setLocalReplacement('BrianHenryIE\Strauss\Pimple');
        $ns2 = new NamespaceSymbol('Pimple\ServiceIterator');
        $ns2->setLocalReplacement('BrianHenryIE\Strauss\Pimple\ServiceIterator');
        $discoveredSymbols = new DiscoveredSymbols([$ns1, $ns2]);

        $dependency = Mockery::mock(ComposerPackage::class);
        $dependency->expects('getAutoload')->andReturn(['psr-0' => ['Pimple' => 'src/']]);
        $dependency->allows('getPackageName')->andReturn('my/package');
        $dependency->expects('hasPsr0')->andReturnTrue();

        $file = Mockery::mock(FileWithDependency::class);
        $file->allows('getSourcePath')->andReturn('vendor/pimple/pimple/src/Pimple/ServiceIterator.php');
        $file->expects('isPhpFile')->andReturnTrue();
        $file->expects('getDependency')->andReturn($dependency);
        $file->expects('getNamespaces')->andReturn($discoveredSymbols);
        $file->expects('getPackageRelativePath')->andReturn('src/Pimple/ServiceIterator.php');
        $file->expects('getTargetAbsolutePath')->andReturn('vendor-prefixed/pimple/pimple/src/Pimple/ServiceIterator.php');
        $file->expects('setTargetAbsolutePath')->once();

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $dependency->expects('getFiles')->andReturn($discoveredFiles);

        $dependencies = new DependenciesCollection([$dependency]);

        $sut = new Psr0($this->getInMemoryFileSystem(), $this->getLogger());
        $sut->setTargetDirectory($dependencies, $discoveredFiles, $discoveredSymbols);

        $this->assertTrue($this->getTestLogger()->hasWarning('More than one namespace in PSR-0 file.'));
    }
}
