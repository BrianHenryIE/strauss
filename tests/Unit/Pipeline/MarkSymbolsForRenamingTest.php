<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\MarkSymbolsForRenamingConfigInterface;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\TestCase;
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
     * @covers ::scanSymbols
     * @covers ::isExcludeFromCopyPackage
     */
    public function testExcludedPackageSymbolsNotMarkedForRenaming(): void
    {
        $package = Mockery::mock(ComposerPackage::class);
        $package->shouldReceive('getPackageName')->andReturn('psr/log');

        $config = Mockery::mock(MarkSymbolsForRenamingConfigInterface::class);
        $config->shouldReceive('getExcludePackagesFromCopy')->andReturn(['psr/log']);
        $config->shouldReceive('getExcludePackagesFromPrefixing')->andReturn([]);
        $config->shouldReceive('getExcludeNamespacesFromPrefixing')->andReturn([]);
        $config->shouldReceive('getExcludeFilePatternsFromPrefixing')->andReturn([]);
        $config->shouldReceive('getVendorDirectory')->andReturn('/vendor/');
        $config->shouldReceive('getTargetDirectory')->andReturn('/vendor-prefixed/');

        $filesystem = Mockery::mock(FileSystem::class);

        $sut = new MarkSymbolsForRenaming($config, $filesystem, $this->getTestLogger());

        $file = new File('/vendor/psr/log/src/LoggerInterface.php', 'psr/log/src/LoggerInterface.php');
        $symbol = new NamespaceSymbol('Psr\Log', $file, '\\', $package);

        self::assertTrue($symbol->isDoRename(), 'Precondition: symbol starts with doRename=true');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($symbol);

        $sut->scanSymbols($discoveredSymbols);

        self::assertFalse($symbol->isDoRename(), 'Symbol from excluded package should have doRename=false');
    }

    /**
     * Symbols from packages NOT in exclude_from_copy.packages should still be marked for renaming.
     *
     * This verifies the fix doesn't break normal operation.
     *
     * @covers ::scanSymbols
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
        $config->shouldReceive('getVendorDirectory')->andReturn('/vendor/');
        $config->shouldReceive('getTargetDirectory')->andReturn('/vendor-prefixed/');

        $filesystem = Mockery::mock(FileSystem::class);

        $sut = new MarkSymbolsForRenaming($config, $filesystem, $this->getTestLogger());

        $file = new File('/vendor/monolog/monolog/src/Logger.php', 'monolog/monolog/src/Logger.php');
        $symbol = new NamespaceSymbol('Monolog', $file, '\\', $package);

        self::assertTrue($symbol->isDoRename(), 'Precondition: symbol starts with doRename=true');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($symbol);

        $sut->scanSymbols($discoveredSymbols);

        self::assertTrue($symbol->isDoRename(), 'Symbol from non-excluded package should remain doRename=true');
    }
}
