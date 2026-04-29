<?php

// Verify there are no // double slashes in paths.

// exclude_from_classmap

// exclude regex

// paths outside project directory

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\FileEnumeratorConfig;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Pipeline\FileEnumerator;
use Mockery;

/**
 * Class FileEnumeratorTest
 * @package BrianHenryIE\Strauss
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\FileEnumerator
 */
class FileEnumeratorTest extends TestCase
{
    /**
     * @covers ::addFile
     */
    public function test_file_does_not_exist(): void
    {
        $config = Mockery::mock(FileEnumeratorConfig::class);
        $filesystem = $this->getInMemoryFileSystem();
        $logger = $this->getLogger();

        $sut = new FileEnumerator($config, $filesystem, $this->getLogger());

        $dependency = Mockery::mock(ComposerPackage::class);
        $dependency->expects('getPackageName')->andReturn('test/package');
        $dependency->expects('getPackageAbsolutePath')->andReturn('path/to/project/vendor/package');

        /** @var ComposerPackage[] $dependencies */
        $dependencies = [$dependency];

        $result = $sut->compileFileListForDependencies($dependencies);

        $this->assertEmpty($result->getFiles());

        $this->assertTrue($this->getTestLogger()->hasWarningRecords());
    }

    /**
     * @covers ::compileFileListForDependencies
     */
    public function testCompileFileListForDependenciesAggregatesAndSortsFiles(): void
    {
        $config = Mockery::mock(FileEnumeratorConfig::class);
        $config->allows('getAbsoluteVendorDirectory')->andReturn('/project/vendor/');
        $config->allows('getAbsoluteTargetDirectory')->andReturn('/project/vendor-prefixed/');

        $filesystem = Mockery::mock(FileSystem::class);
        $filesystem->expects('findAllFilesAbsolutePaths')
            ->with(['/project/vendor/vendor-b'])
            ->andReturn(
                [
                    '/project/vendor/vendor-b/src/Z.php',
                    '/project/vendor/vendor-b/src/B.php',
                ]
            );
        $filesystem->expects('findAllFilesAbsolutePaths')
            ->with(['/project/vendor/vendor-a'])
            ->andReturn(['/project/vendor/vendor-a/src/A.php']);
        $filesystem->allows('directoryExists')->andReturnFalse();
        $filesystem->allows('fileExists')->andReturnTrue();
        $filesystem->allows('getRelativePath')->andReturnArg(1);

        $sut = new FileEnumerator($config, $filesystem, $this->getLogger());

        $dependencyB = $this->mockDependency(
            'vendor/vendor-b',
            '/project/vendor/vendor-b',
            'vendor/vendor-b/'
        );
        $dependencyA = $this->mockDependency(
            'vendor/vendor-a',
            '/project/vendor/vendor-a',
            'vendor/vendor-a/'
        );

        $result = $sut->compileFileListForDependencies([$dependencyB, $dependencyA]);

        $this->assertSame(
            [
                '/project/vendor/vendor-a/src/A.php',
                '/project/vendor/vendor-b/src/B.php',
                '/project/vendor/vendor-b/src/Z.php',
            ],
            array_keys($result->getFiles())
        );
    }

    /**
     * @covers ::compileFileListForDependencies
     * @covers ::compileFileListForPaths
     */
    public function testCompileFileListForDependenciesMatchesPathsSourceSet(): void
    {
        $filesystem = $this->getInMemoryFileSystem();
        $filesystem->createDirectory('mem://project/vendor/vendor-a/src');
        $filesystem->write('mem://project/vendor/vendor-a/src/B.php', '<?php class BClass {}');
        $filesystem->write('mem://project/vendor/vendor-a/src/A.php', '<?php class AClass {}');

        $config = Mockery::mock(FileEnumeratorConfig::class);
        $config->allows('getAbsoluteVendorDirectory')->andReturn('mem://project/vendor/');
        $config->allows('getAbsoluteTargetDirectory')->andReturn('mem://project/vendor-prefixed/');

        $dependencyForDependencies = $this->mockDependency(
            'vendor/vendor-a',
            'mem://project/vendor/vendor-a',
            'vendor/vendor-a/'
        );

        $dependencyForPaths = $this->mockDependency(
            'vendor/vendor-a',
            'mem://project/vendor/vendor-a',
            'vendor/vendor-a/'
        );

        $sutDependencies = new FileEnumerator($config, $filesystem, $this->getLogger());
        $sutPaths = new FileEnumerator($config, $filesystem, $this->getLogger());

        $dependenciesResult = $sutDependencies->compileFileListForDependencies([$dependencyForDependencies]);
        $pathsResult = $sutPaths->compileFileListForPaths(
            ['mem://project/vendor/vendor-a'],
            $dependencyForPaths
        );

        $this->assertSame(
            array_keys($dependenciesResult->getFiles()),
            array_keys($pathsResult->getFiles())
        );
    }

    private function mockDependency(
        string $packageName,
        string $absolutePath,
        string $relativePath
    ): ComposerPackage {
        /** @var ComposerPackage&\Mockery\MockInterface $dependency */
        $dependency = Mockery::mock(ComposerPackage::class);
        $dependency->allows('getPackageName')->andReturn($packageName);
        $dependency->allows('getPackageAbsolutePath')->andReturn($absolutePath);
        $dependency->allows('getRelativePath')->andReturn($relativePath);
        $dependency->allows('addFile');

        return $dependency;
    }
}
