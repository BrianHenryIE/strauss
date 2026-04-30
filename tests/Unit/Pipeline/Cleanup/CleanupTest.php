<?php

namespace BrianHenryIE\Strauss\Pipeline\Cleanup;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\CleanupConfigInterface;
use BrianHenryIE\Strauss\Config\OptimizeAutoloaderConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use Mockery;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\Cleanup\Cleanup
 */
class CleanupTest extends \BrianHenryIE\Strauss\TestCase
{
    public function test_optimize_autoloader_defaults_to_true_without_capability_interface(): void
    {
        $config = Mockery::mock(CleanupConfigInterface::class);
        $config->expects('isDeleteVendorFiles')->once()->andReturnFalse();
        $config->expects('isDeleteVendorPackages')->once()->andReturnFalse();

        $sut = new class($config, $this->getFileSystem(), new NullLogger()) extends Cleanup {
            public function optimizeEnabledForTest(): bool
            {
                return $this->isOptimizeAutoloaderEnabled();
            }
        };

        $this->assertTrue($sut->optimizeEnabledForTest());
    }

    public function test_optimize_autoloader_uses_capability_interface_when_available(): void
    {
        $config = Mockery::mock(
            CleanupConfigInterface::class,
            OptimizeAutoloaderConfigInterface::class
        );
        $config->expects('isDeleteVendorFiles')->once()->andReturnFalse();
        $config->expects('isDeleteVendorPackages')->once()->andReturnFalse();
        $config->expects('isOptimizeAutoloader')->once()->andReturnFalse();

        $sut = new class($config, $this->getFileSystem(), new NullLogger()) extends Cleanup {
            public function optimizeEnabledForTest(): bool
            {
                return $this->isOptimizeAutoloaderEnabled();
            }
        };

        $this->assertFalse($sut->optimizeEnabledForTest());
    }

    public function test_delete_vendor_files_deletes_only_marked_files_and_prunes_empty_directories(): void
    {
        $filesystem = $this->getInMemoryFileSystem();
        $filesystem->createDirectory('vendor/vendor-a/src');
        $filesystem->createDirectory('vendor/vendor-a/empty');
        $filesystem->createDirectory('vendor/vendor-a/nonempty');
        $filesystem->write('vendor/vendor-a/src/DeleteMe.php', '<?php');
        $filesystem->write('vendor/vendor-a/src/KeepMe.php', '<?php');
        $filesystem->write('vendor/vendor-a/empty/DeleteMe.php', '<?php');
        $filesystem->write('vendor/vendor-a/nonempty/DeleteMe.php', '<?php');
        $filesystem->write('vendor/vendor-a/nonempty/KeepMe.php', '<?php');

        $deleteFromSrc = new File('mem://vendor/vendor-a/src/DeleteMe.php', 'vendor-a/src/DeleteMe.php');
        $deleteFromSrc->setDoDelete(true);
        $keepInSrc = new File('mem://vendor/vendor-a/src/KeepMe.php', 'vendor-a/src/KeepMe.php');
        $keepInSrc->setDoDelete(false);
        $deleteFromEmpty = new File('mem://vendor/vendor-a/empty/DeleteMe.php', 'vendor-a/empty/DeleteMe.php');
        $deleteFromEmpty->setDoDelete(true);
        $deleteFromNonEmpty = new File('mem://vendor/vendor-a/nonempty/DeleteMe.php', 'vendor-a/nonempty/DeleteMe.php');
        $deleteFromNonEmpty->setDoDelete(true);

        $discoveredFiles = new DiscoveredFiles();
        foreach ([$deleteFromSrc, $keepInSrc, $deleteFromEmpty, $deleteFromNonEmpty] as $file) {
            $discoveredFiles->add($file);
        }

        $sut = new Cleanup(
            $this->cleanupConfig(deleteVendorFiles: true),
            $filesystem,
            new NullLogger()
        );

        $sut->deleteFiles([], $discoveredFiles);

        self::assertFalse($filesystem->fileExists('vendor/vendor-a/src/DeleteMe.php'));
        self::assertTrue($filesystem->fileExists('vendor/vendor-a/src/KeepMe.php'));
        self::assertFalse($filesystem->directoryExists('vendor/vendor-a/empty'));
        self::assertTrue($filesystem->fileExists('vendor/vendor-a/nonempty/KeepMe.php'));
        self::assertTrue($deleteFromSrc->getDidDelete());
        self::assertFalse($keepInSrc->getDidDelete());
        self::assertTrue($deleteFromEmpty->getDidDelete());
        self::assertTrue($deleteFromNonEmpty->getDidDelete());
    }

    public function test_delete_vendor_packages_skips_excluded_packages_and_only_deletes_empty_parent_directories(): void
    {
        $filesystem = $this->getInMemoryFileSystem();
        $filesystem->createDirectory('vendor/vendor-a/delete');
        $filesystem->createDirectory('vendor/vendor-b/delete');
        $filesystem->createDirectory('vendor/vendor-b/keep');
        $filesystem->createDirectory('vendor/vendor-c/excluded');
        $filesystem->write('vendor/vendor-a/delete/File.php', '<?php');
        $filesystem->write('vendor/vendor-b/delete/File.php', '<?php');
        $filesystem->write('vendor/vendor-b/keep/File.php', '<?php');
        $filesystem->write('vendor/vendor-c/excluded/File.php', '<?php');

        $deletedWithEmptyParent = $this->packageMock('vendor-a/delete', 'mem://vendor/vendor-a/delete');
        $deletedWithNonEmptyParent = $this->packageMock('vendor-b/delete', 'mem://vendor/vendor-b/delete');
        $excluded = $this->packageMock('vendor-c/excluded', 'mem://vendor/vendor-c/excluded');

        $deletedWithEmptyParent->expects('setDidDelete')->once()->with(true);
        $deletedWithNonEmptyParent->expects('setDidDelete')->once()->with(true);
        $excluded->shouldNotReceive('setDidDelete');

        $sut = new Cleanup(
            $this->cleanupConfig(deleteVendorPackages: true, excludePackagesFromCopy: ['vendor-c/excluded']),
            $filesystem,
            new NullLogger()
        );

        $sut->deleteFiles(
            [
                'vendor-a/delete' => $deletedWithEmptyParent,
                'vendor-b/delete' => $deletedWithNonEmptyParent,
                'vendor-c/excluded' => $excluded,
            ],
            new DiscoveredFiles()
        );

        self::assertFalse($filesystem->directoryExists('vendor/vendor-a'));
        self::assertFalse($filesystem->directoryExists('vendor/vendor-b/delete'));
        self::assertTrue($filesystem->fileExists('vendor/vendor-b/keep/File.php'));
        self::assertTrue($filesystem->fileExists('vendor/vendor-c/excluded/File.php'));
    }

    private function cleanupConfig(
        bool $deleteVendorFiles = false,
        bool $deleteVendorPackages = false,
        array $excludePackagesFromCopy = []
    ): CleanupConfigInterface {
        $config = Mockery::mock(CleanupConfigInterface::class);
        $config->shouldReceive('isDeleteVendorFiles')->andReturn($deleteVendorFiles);
        $config->shouldReceive('isDeleteVendorPackages')->andReturn($deleteVendorPackages);
        $config->shouldReceive('getAbsoluteVendorDirectory')->andReturn('mem://vendor');
        $config->shouldReceive('getAbsoluteTargetDirectory')->andReturn('mem://vendor-prefixed');
        $config->shouldReceive('getExcludePackagesFromCopy')->andReturn($excludePackagesFromCopy);

        return $config;
    }

    /**
     * @return ComposerPackage&\Mockery\MockInterface
     */
    private function packageMock(string $packageName, string $absolutePath): ComposerPackage
    {
        /** @var ComposerPackage&\Mockery\MockInterface $package */
        $package = Mockery::mock(ComposerPackage::class);
        $package->shouldReceive('getPackageName')->andReturn($packageName);
        $package->shouldReceive('getPackageAbsolutePath')->andReturn($absolutePath);
        $package->shouldReceive('getRelativePath')->andReturn($packageName);

        return $package;
    }
}
