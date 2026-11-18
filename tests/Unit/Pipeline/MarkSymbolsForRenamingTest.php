<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\MarkSymbolsForRenamingConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\MarkSymbolsForRenaming
 */
class MarkSymbolsForRenamingTest extends TestCase
{
    /**
     * Symbols from packages in exclude_from_copy.packages should NOT be marked for renaming.
     *
     * This is the fix for the bug where symbols from excluded packages were still being prefixed,
     * causing references to those packages to break.
     *
     * @covers ::scanSetDoRename
     * @covers ::isExcludeFromCopyPackage
     */
    public function testExcludedPackageSymbolsNotMarkedForRenaming(): void
    {
        $package = Mockery::mock(ComposerPackage::class);
        $package->shouldReceive('getPackageName')->andReturn('psr/log');
        $package->shouldReceive('getPackageAbsolutePath')->andReturn('project/vendor/psr/log');
        $package->shouldReceive('addFile');

        $config = Mockery::mock(MarkSymbolsForRenamingConfigInterface::class);
        $config->shouldReceive('getExcludePackagesFromCopy')->andReturn(['psr/log']);
        $config->shouldReceive('getExcludePackagesFromPrefixing')->andReturn([]);
        $config->shouldReceive('getExcludeNamespacesFromPrefixing')->andReturn([]);
        $config->shouldReceive('getExcludeFilePatternsFromPrefixing')->andReturn([]);
        $config->shouldReceive('getExcludePackagesFromConstantPrefixing')->andReturn([]);
        $config->shouldReceive('getExcludeNamespacesFromConstantPrefixing')->andReturn([]);
        $config->shouldReceive('getExcludeFilePatternsFromConstantPrefixing')->andReturn([]);
        $config->shouldReceive('getExcludeConstantNames')->andReturn([]);
        $config->shouldReceive('getAbsoluteVendorDirectory')->andReturn('vendor');
        $config->shouldReceive('getAbsoluteTargetDirectory')->andReturn('vendor-prefixed');
        $config->shouldReceive('isTargetDirectoryVendor')->andReturnFalse();
        $config->shouldReceive('getNamespaceReplacementPatterns')->andReturn([]);

        $filesystem = Mockery::mock(FileSystem::class);

        $sut = new MarkSymbolsForRenaming($config, $filesystem, $this->getTestLogger());

        $file = new FileWithDependency(
            $package,
            'psr/log/src/LoggerInterface.php',
            'project/vendor/psr/log/src/LoggerInterface.php',
            'project/vendor-prefixed/psr/log/src/LoggerInterface.php'
        );
        $symbol = new NamespaceSymbol('Psr\Log', $file);
        $symbol->setIsAutoloaded(true);
        $symbol->setDoRename(true);

        $this->assertTrue($symbol->isDoRename(), 'Precondition: symbol starts with doRename=true');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($symbol);

        $sut->scanSetDoRename($discoveredSymbols);

        $this->assertFalse($symbol->isDoRename(), 'Symbol from excluded package should have doRename=false');
    }

    /**
     * Symbols from packages NOT in exclude_from_copy.packages should still be marked for renaming.
     *
     * This verifies the fix doesn't break normal operation.
     *
     * @covers ::scanSetDoRename
     * @covers ::isExcludeFromCopyPackage
     */
    public function testNonExcludedPackageSymbolsStillMarkedForRenaming(): void
    {
        $package = Mockery::mock(ComposerPackage::class);
        $package->shouldReceive('getPackageName')->andReturn('monolog/monolog');

        $config = Mockery::mock(MarkSymbolsForRenamingConfigInterface::class);
        $config->shouldReceive('getExcludePackagesFromCopy')->andReturn(['psr/log']); // Different package
        $config->shouldReceive('getExcludePackagesFromPrefixing')->andReturn([]);
        $config->shouldReceive('getExcludeNamespacesFromPrefixing')->andReturn([]);
        $config->shouldReceive('getExcludeFilePatternsFromPrefixing')->andReturn([]);
        $config->shouldReceive('getExcludePackagesFromConstantPrefixing')->andReturn([]);
        $config->shouldReceive('getExcludeNamespacesFromConstantPrefixing')->andReturn([]);
        $config->shouldReceive('getExcludeFilePatternsFromConstantPrefixing')->andReturn([]);
        $config->shouldReceive('getExcludeConstantNames')->andReturn([]);
        $config->shouldReceive('getAbsoluteVendorDirectory')->andReturn('vendor');
        $config->shouldReceive('getAbsoluteTargetDirectory')->andReturn('vendor-prefixed');
        $config->shouldReceive('getNamespaceReplacementPatterns')->andReturn([]);
        $config->shouldReceive('isTargetDirectoryVendor')->andReturnFalse();

        $filesystem = Mockery::mock(FileSystem::class);

        $sut = new MarkSymbolsForRenaming($config, $filesystem, $this->getTestLogger());

        $file = new File(
            'vendor/monolog/monolog/src/Logger.php',
            'monolog/monolog/src/Logger.php',
            'vendor-prefixed/monolog/monolog/src/Logger.php'
        );
        $symbol = new NamespaceSymbol('Monolog', $file);
        $symbol->setIsAutoloaded(true);
        $symbol->setDoRename(true);

        $this->assertTrue($symbol->isDoRename(), 'Precondition: symbol starts with doRename=true');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($symbol);

        $sut->scanSetDoRename($discoveredSymbols);

        $this->assertTrue($symbol->isDoRename(), 'Symbol from non-excluded package should remain doRename=true');
    }

    /**
     * Constants listed in exclude_constants.constants should NOT be marked for renaming.
     *
     * @covers ::scanSetDoRename
     * @covers ::isExcludeConstants
     * @covers ::isExcludeConstantName
     */
    public function testExcludeConstantsByNameNotMarkedForRenaming(): void
    {
        $package = Mockery::mock(ComposerPackage::class);
        $package->shouldReceive('getPackageName')->andReturn('some/package')->zeroOrMoreTimes();

        $config = Mockery::mock(MarkSymbolsForRenamingConfigInterface::class);
        $config->shouldReceive('getExcludePackagesFromCopy')->andReturn([]);
        $config->shouldReceive('getExcludePackagesFromPrefixing')->andReturn([]);
        $config->shouldReceive('getExcludeNamespacesFromPrefixing')->andReturn([]);
        $config->shouldReceive('getExcludeFilePatternsFromPrefixing')->andReturn([]);
        $config->shouldReceive('getExcludePackagesFromConstantPrefixing')->andReturn([]);
        $config->shouldReceive('getExcludeNamespacesFromConstantPrefixing')->andReturn([]);
        $config->shouldReceive('getExcludeFilePatternsFromConstantPrefixing')->andReturn([]);
        $config->shouldReceive('getExcludeConstantNames')->andReturn(['WP_PLUGIN_DIR', 'ABSPATH']);
        $config->shouldReceive('getUnprefixedPackages')->andReturn([]);
        $config->shouldReceive('getAbsoluteVendorDirectory')->andReturn('vendor');
        $config->shouldReceive('getAbsoluteTargetDirectory')->andReturn('vendor');
        $config->shouldReceive('isTargetDirectoryVendor')->andReturnFalse();
        $config->shouldReceive('getNamespaceReplacementPatterns')->andReturn([]);

        $filesystem = Mockery::mock(FileSystem::class);

        $sut = new MarkSymbolsForRenaming($config, $filesystem, $this->getTestLogger());

        $dependency = Mockery::mock(ComposerPackage::class);
        $dependency->expects('getPackageName')->andReturn('some/package')->zeroOrMoreTimes();
        $dependency->expects('getPackageAbsolutePath')->andReturn('vendor/some/package');
        $dependency->expects('addFile');
        $dependency->allows('hasPsr0')->andReturnFalse();

        $file = new FileWithDependency(
            $dependency,
            'vendor/some/package/src/bootstrap.php',
            'some/package/src/bootstrap.php',
            'vendor-prefixed/some/package/src/bootstrap.php'
        );
        $file->addAutoloader('classmap');

        $symbol = new ConstantSymbol('WP_PLUGIN_DIR', $file, new NamespaceSymbol('\\'));
        $symbol->setIsAutoloaded(true);
        $symbol->setDoRename(true);

        $this->assertTrue($symbol->isDoRename(), 'Precondition: symbol starts with doRename=true');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($symbol);

        $sut->scanSetDoRename($discoveredSymbols);

        $this->assertFalse($symbol->isDoRename(), 'Constant in exclude_constants.constants should have doRename=false');
    }

    /**
     * When prefixing in place, the other installed packages are scanned so their uses of the prefixed symbols are
     * updated, but their own symbols are not renamed, even though they are autoloaded.
     *
     * @covers ::scanSetDoRename
     * @covers ::isExcludeFromPrefixPackage
     */
    public function testUnprefixedPackageSymbolsNotMarkedForRenaming(): void
    {
        $package = Mockery::mock(ComposerPackage::class);
        $package->shouldReceive('getPackageName')->andReturn('monolog/monolog');
        $package->shouldReceive('getPackageAbsolutePath')->andReturn('project/vendor/monolog/monolog');
        $package->shouldReceive('addFile');

        $config = Mockery::mock(MarkSymbolsForRenamingConfigInterface::class);
        $config->shouldReceive('getExcludePackagesFromCopy')->andReturn([]);
        $config->shouldReceive('getExcludePackagesFromPrefixing')->andReturn([]);
        $config->shouldReceive('getUnprefixedPackages')->andReturn(['monolog/monolog' => $package]);
        $config->shouldReceive('getExcludeNamespacesFromPrefixing')->andReturn([]);
        $config->shouldReceive('getExcludeFilePatternsFromPrefixing')->andReturn([]);
        $config->shouldReceive('getExcludeConstantNames')->andReturn([]);
        $config->shouldReceive('isTargetDirectoryVendor')->andReturnTrue();
        $config->shouldReceive('getNamespaceReplacementPatterns')->andReturn([]);

        $sut = new MarkSymbolsForRenaming($config, Mockery::mock(FileSystem::class), $this->getTestLogger());

        $file = new FileWithDependency(
            $package,
            'monolog/monolog/src/Monolog/Logger.php',
            'project/vendor/monolog/monolog/src/Monolog/Logger.php',
            'project/vendor/monolog/monolog/src/Monolog/Logger.php'
        );
        $symbol = new NamespaceSymbol('Monolog', $file);
        $symbol->setIsAutoloaded(true);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($symbol);

        $sut->scanSetDoRename($discoveredSymbols);

        $this->assertFalse($symbol->isDoRename());
    }

    /**
     * @param array<string,ComposerPackage> $packagesToPrefix
     */
    protected function getSutForDoPrefix(array $packagesToPrefix): MarkSymbolsForRenaming
    {
        $config = Mockery::mock(MarkSymbolsForRenamingConfigInterface::class);
        $config->shouldReceive('getPackagesToPrefix')->andReturn($packagesToPrefix);

        return new MarkSymbolsForRenaming($config, Mockery::mock(FileSystem::class), $this->getTestLogger());
    }

    /**
     * @return ComposerPackage&\Mockery\MockInterface
     */
    protected function getPackageForDoPrefix(string $packageName): ComposerPackage
    {
        $package = Mockery::mock(ComposerPackage::class);
        $package->shouldReceive('getPackageName')->andReturn($packageName);
        $package->shouldReceive('getPackageAbsolutePath')->andReturn('/project/vendor/' . $packageName);
        $package->shouldReceive('addFile');

        return $package;
    }

    protected function getPackageFile(ComposerPackage $package, string $packageRelativePath): FileWithDependency
    {
        $vendorRelativePath = $package->getPackageName() . '/' . $packageRelativePath;

        $file = new FileWithDependency(
            $package,
            $vendorRelativePath,
            '/project/vendor/' . $vendorRelativePath,
            '/project/vendor-prefixed/' . $vendorRelativePath
        );
        // As {@see AutoloadedEnumerator} marks autoloaded files.
        $file->setDoPrefix(true);

        return $file;
    }

    /**
     * Files of packages not being prefixed, e.g. `exclude_from_prefix.packages`, even when autoloaded, and files which
     * are not PHP, are not prefixed.
     *
     * @covers ::scanSetDoPrefix
     */
    public function test_files_of_packages_not_to_prefix_are_not_prefixed(): void
    {
        $vendorA = $this->getPackageForDoPrefix('vendor/vendor-a');
        $vendorB = $this->getPackageForDoPrefix('vendor/vendor-b');

        $sut = $this->getSutForDoPrefix(['vendor/vendor-b' => $vendorB]);

        $fileOutside = $this->getPackageFile($vendorA, 'src/file.php');
        $fileInside = $this->getPackageFile($vendorB, 'src/file.php');
        $nonPhpFileInside = $this->getPackageFile($vendorB, 'README.md');
        $projectFile = new File('/project/src/file.php', 'src/file.php', '/project/src/file.php');

        $sut->scanSetDoPrefix(new DiscoveredFiles([$fileOutside, $fileInside, $nonPhpFileInside, $projectFile]));

        $this->assertFalse($fileOutside->isDoPrefix());
        $this->assertTrue($fileInside->isDoPrefix());
        $this->assertFalse($nonPhpFileInside->isDoPrefix());
        $this->assertTrue($projectFile->isDoPrefix());
    }

    /**
     * A file already marked not to prefix, e.g. by {@see FileCopyScanner} because it is not copied, stays so.
     *
     * @covers ::scanSetDoPrefix
     */
    public function test_files_marked_not_to_prefix_stay_so(): void
    {
        $vendorB = $this->getPackageForDoPrefix('vendor/vendor-b');

        $sut = $this->getSutForDoPrefix(['vendor/vendor-b' => $vendorB]);

        $file = $this->getPackageFile($vendorB, 'src/file.php');
        $file->setDoPrefix(false);

        $sut->scanSetDoPrefix(new DiscoveredFiles([$file]));

        $this->assertFalse($file->isDoPrefix());
    }
}
