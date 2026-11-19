<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\FileCopyScannerConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\TestCase;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\FileCopyScanner
 */
class FileCopyScannerTest extends TestCase
{
    /**
     * @covers ::__construct
     * @covers ::isFilePathExcluded
     */
    public function test_file_is_excluded(): void
    {
        $vendorRelativePath = 'my/package/file.php';
        $regexPattern = "~^([^/]*?/){2}file.php~";

        $dependency = Mockery::mock(ComposerPackage::class);

        $dependency->expects('getPackageAbsolutePath')->andReturn('path/to/project/vendor/my/package');
        $dependency->expects('addFile');
        $dependency->expects('getPackageName')->andReturn('my/package');
        //        $dependency->expects('getRelativePath')->andReturn('my/package');

        $file = new FileWithDependency(
            $dependency,
            $vendorRelativePath,
            'path/to/project/vendor/my/package/file.php',
            'path/to/project/vendor-prefixed/my/package/file.php'
        );

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $config = \Mockery::mock(FileCopyScannerConfigInterface::class);
        $config->expects('isTargetDirectoryVendor')->atLeast()->once()->andReturnFalse();
        $config->expects('getExcludePackagesFromCopy')->andReturns([]);
        $config->expects('isDeleteVendorFiles')->andReturnFalse();
        $config->expects('getExcludeNamespacesFromCopy')->andReturns([]);
        $config->expects('getExcludeFilePatternsFromCopy')->andReturns([$regexPattern]);

        $filesystem = $this->getFileSystem();

        $sut = new FileCopyScanner($config, $filesystem, $this->getLogger());
        $sut->scanFiles($discoveredFiles);

        $this->assertFalse($file->isDoCopy());
    }

    /**
     * The packages which are not prefixed are only read, so their uses of the prefixed symbols can be updated.
     *
     * @covers ::scanFiles
     */
    public function test_unprefixed_package_files_are_not_deleted(): void
    {
        $prefixedPackage = Mockery::mock(ComposerPackage::class);
        $prefixedPackage->shouldReceive('getPackageAbsolutePath')->andReturn('path/to/project/vendor/psr/log');
        $prefixedPackage->shouldReceive('addFile');
        $prefixedPackage->shouldReceive('getPackageName')->andReturn('psr/log');

        $unprefixedPackage = Mockery::mock(ComposerPackage::class);
        $unprefixedPackage->shouldReceive('getPackageAbsolutePath')->andReturn('path/to/project/vendor/monolog/monolog');
        $unprefixedPackage->shouldReceive('addFile');
        $unprefixedPackage->shouldReceive('getPackageName')->andReturn('monolog/monolog');

        $prefixedFile = new FileWithDependency(
            $prefixedPackage,
            'psr/log/src/LoggerInterface.php',
            'path/to/project/vendor/psr/log/src/LoggerInterface.php',
            'path/to/project/vendor/psr/log/src/LoggerInterface.php'
        );
        $unprefixedFile = new FileWithDependency(
            $unprefixedPackage,
            'monolog/monolog/src/Monolog/Logger.php',
            'path/to/project/vendor/monolog/monolog/src/Monolog/Logger.php',
            'path/to/project/vendor/monolog/monolog/src/Monolog/Logger.php'
        );

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($prefixedFile);
        $discoveredFiles->add($unprefixedFile);

        $config = Mockery::mock(FileCopyScannerConfigInterface::class);
        $config->shouldReceive('isTargetDirectoryVendor')->andReturnTrue();
        $config->shouldReceive('getExcludePackagesFromCopy')->andReturn([]);
        $config->shouldReceive('isDeleteVendorFiles')->andReturnTrue();
        $config->shouldReceive('getExcludeNamespacesFromCopy')->andReturn([]);
        $config->shouldReceive('getExcludeFilePatternsFromCopy')->andReturn([]);
        $config->shouldReceive('getUnprefixedPackages')->andReturn(['monolog/monolog' => $unprefixedPackage]);

        $filesystem = Mockery::mock(\BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem::class);
        $filesystem->shouldReceive('isSymlinked')->andReturnFalse();

        $sut = new FileCopyScanner($config, $filesystem, $this->getLogger());
        $sut->scanFiles($discoveredFiles);

        $this->assertTrue($prefixedFile->isDoDelete());
        $this->assertFalse($unprefixedFile->isDoDelete());
    }
}
