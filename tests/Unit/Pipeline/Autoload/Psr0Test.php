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
     * TODO: non-PHP files in directories that are being renamed should be moved too, the classes might need them.
     *
     * @covers ::setTargetDirectory
     */
    public function test_non_php_file_is_skipped(): void
    {
        $this->markTestIncomplete();

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
        $namespaceSymbol->setDoRename(true);

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
        $file->expects('addAutoloader');

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $dependency->expects('getFiles')->andReturn($discoveredFiles);

        $dependencies = new DependenciesCollection([$dependency]);

        $sut = new Psr0($this->getInMemoryFileSystem(), $this->getLogger());
        $sut->setTargetDirectory($dependencies, $discoveredFiles, $discoveredSymbols);
    }

    /**
     * `"autoload": {"psr-0": {"Pimple": "src/"}}`: the namespace's single directory as a string.
     *
     * @covers ::setTargetDirectory
     */
    public function test_psr0_path_as_string(): void
    {
        $namespaceSymbol = new NamespaceSymbol('Pimple');
        $namespaceSymbol->setLocalReplacement('BrianHenryIE\Strauss\Pimple');
        $namespaceSymbol->setDoRename(true);

        $discoveredSymbols = new DiscoveredSymbols([$namespaceSymbol]);

        $inPath = $this->getPsr0File('src/Pimple/Container.php');
        $inPath->expects('setTargetAbsolutePath')
            ->with('project/vendor-prefixed/pimple/pimple/src/BrianHenryIE/Strauss/Pimple/Container.php')
            ->once();
        $inPath->expects('addAutoloader')->with('psr-0')->once();

        $notInPath = $this->getPsr0File('tests/Pimple/ContainerTest.php');
        $notInPath->shouldNotReceive('setTargetAbsolutePath');
        $notInPath->shouldNotReceive('addAutoloader');

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($inPath);
        $discoveredFiles->add($notInPath);

        $dependencies = $this->getPsr0Dependencies(['Pimple' => 'src/'], $discoveredFiles);

        $sut = new Psr0($this->getInMemoryFileSystem(), $this->getLogger());
        $sut->setTargetDirectory($dependencies, $discoveredFiles, $discoveredSymbols);
    }

    /**
     * `"autoload": {"psr-0": {"Pimple": ["src/", "lib/"]}}`: the namespace's several directories as an array.
     *
     * @covers ::setTargetDirectory
     */
    public function test_psr0_paths_as_array(): void
    {
        $namespaceSymbol = new NamespaceSymbol('Pimple');
        $namespaceSymbol->setLocalReplacement('BrianHenryIE\Strauss\Pimple');
        $namespaceSymbol->setDoRename(true);

        $discoveredSymbols = new DiscoveredSymbols([$namespaceSymbol]);

        $inFirstPath = $this->getPsr0File('src/Pimple/Container.php');
        $inFirstPath->expects('setTargetAbsolutePath')
            ->with('project/vendor-prefixed/pimple/pimple/src/BrianHenryIE/Strauss/Pimple/Container.php')
            ->once();
        $inFirstPath->expects('addAutoloader')->with('psr-0')->once();

        $inSecondPath = $this->getPsr0File('lib/Pimple/ServiceIterator.php');
        $inSecondPath->expects('setTargetAbsolutePath')
            ->with('project/vendor-prefixed/pimple/pimple/lib/BrianHenryIE/Strauss/Pimple/ServiceIterator.php')
            ->once();
        $inSecondPath->expects('addAutoloader')->with('psr-0')->once();

        $notInPath = $this->getPsr0File('tests/Pimple/ContainerTest.php');
        $notInPath->shouldNotReceive('setTargetAbsolutePath');
        $notInPath->shouldNotReceive('addAutoloader');

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($inFirstPath);
        $discoveredFiles->add($inSecondPath);
        $discoveredFiles->add($notInPath);

        $dependencies = $this->getPsr0Dependencies(['Pimple' => ['src/', 'lib/']], $discoveredFiles);

        $sut = new Psr0($this->getInMemoryFileSystem(), $this->getLogger());
        $sut->setTargetDirectory($dependencies, $discoveredFiles, $discoveredSymbols);
    }

    /**
     * A file in the pimple/pimple package, copied to `project/vendor-prefixed/pimple/pimple/`.
     *
     * @param string $packageRelativePath The file's path inside the package, e.g. `src/Pimple/Container.php`.
     *
     * @return FileWithDependency&\Mockery\MockInterface
     */
    protected function getPsr0File(string $packageRelativePath)
    {
        $file = Mockery::mock(FileWithDependency::class);
        $file->allows('getSourcePath')->andReturn('project/vendor/pimple/pimple/' . $packageRelativePath);
        $file->allows('getPackageRelativePath')->andReturn($packageRelativePath);
        $file->allows('getTargetAbsolutePath')->andReturn('project/vendor-prefixed/pimple/pimple/' . $packageRelativePath);

        return $file;
    }

    /**
     * @param array<string, string|string[]> $psr0            The package's `autoload.psr-0` key.
     * @param DiscoveredFiles                $discoveredFiles The package's files.
     */
    protected function getPsr0Dependencies(array $psr0, DiscoveredFiles $discoveredFiles): DependenciesCollection
    {
        $dependency = Mockery::mock(ComposerPackage::class);
        $dependency->expects('hasPsr0')->andReturnTrue();
        $dependency->expects('getAutoload')->andReturn(['psr-0' => $psr0]);
        $dependency->expects('getFiles')->andReturn($discoveredFiles);
        $dependency->allows('getPackageName')->andReturn('pimple/pimple');

        return new DependenciesCollection([$dependency]);
    }
}
