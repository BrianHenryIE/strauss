<?php

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Mockery;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Console\Commands\DependenciesCommand
 */
class DependenciesCommandComposerRuntimeSymbolsTest extends IntegrationTestCase
{
    /**
     * @return DependenciesCommand&object{getDiscoveredSymbolsWithComposerRuntime(): DiscoveredSymbols}
     */
    protected function getSut(StraussConfig $config, FileSystem $filesystem, DiscoveredSymbols $discoveredSymbols): DependenciesCommand
    {
        return new class ($config, $filesystem, $discoveredSymbols) extends DependenciesCommand {
            public function __construct(StraussConfig $config, FileSystem $filesystem, DiscoveredSymbols $discoveredSymbols)
            {
                parent::__construct();
                $this->config = $config;
                $this->filesystem = $filesystem;
                $this->logger = new NullLogger();
                $this->discoveredSymbols = $discoveredSymbols;
            }

            public function getDiscoveredSymbolsWithComposerRuntime(): DiscoveredSymbols
            {
                return parent::getDiscoveredSymbolsWithComposerRuntime();
            }
        };
    }

    protected function getConfig(bool $isClassmapOutput, string $vendorDirectory): StraussConfig
    {
        $config = Mockery::mock(StraussConfig::class);
        $config->allows('isClassmapOutput')->andReturn($isClassmapOutput);
        $config->allows('getAbsoluteVendorDirectory')->andReturn($vendorDirectory);
        $config->allows('getNamespacePrefix')->andReturn('Prefix');
        $config->allows('isDryRun')->andReturnFalse();
        return $config;
    }

    /**
     * A global class and a namespace with the same name (wp-graphql's `WPGraphQL`) must both survive the merge.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/66
     *
     * @covers ::getDiscoveredSymbolsWithComposerRuntime
     */
    public function test_symbols_of_different_types_with_the_same_name_are_kept(): void
    {
        $file = new File('/vendor/wp-graphql/wp-graphql/src/WPGraphQL.php', 'wp-graphql/wp-graphql/src/WPGraphQL.php', '/vendor-prefixed/wp-graphql/wp-graphql/src/WPGraphQL.php');
        $global = new NamespaceSymbol('\\');
        $namespace = new NamespaceSymbol('WPGraphQL', $file);
        $class = new ClassSymbol('WPGraphQL', $file, $global);
        $discoveredSymbols = new DiscoveredSymbols([$global, $namespace, $class]);

        // No Composer files exist in the (in-memory) vendor directory, so only the merge itself is exercised.
        $sut = $this->getSut($this->getConfig(true, '/vendor'), $this->getInMemoryFileSystem(), $discoveredSymbols);

        $result = $sut->getDiscoveredSymbolsWithComposerRuntime();

        self::assertSame($class, $result->getClass('WPGraphQL'));
        self::assertSame($namespace, $result->getNamespace('WPGraphQL'));
        self::assertCount(3, $result);
    }

    /**
     * The Composer runtime classes are added for planning, without being added to the command's own collection.
     *
     * @covers ::getDiscoveredSymbolsWithComposerRuntime
     */
    public function test_composer_runtime_symbols_are_added_to_a_copy(): void
    {
        $filesystem = $this->getFileSystem();
        $vendorDirectory = $this->testsWorkingDir . '/vendor';
        $filesystem->write(
            $vendorDirectory . '/composer/InstalledVersions.php',
            "<?php\nnamespace Composer;\nclass InstalledVersions {}\n"
        );
        $filesystem->write(
            $vendorDirectory . '/composer/autoload_real.php',
            "<?php\nclass ComposerAutoloaderInitabc123 {}\n"
        );

        $file = new File('/vendor/acme/package/src/Thing.php', 'acme/package/src/Thing.php', '/vendor-prefixed/acme/package/src/Thing.php');
        $namespace = new NamespaceSymbol('Acme', $file);
        $discoveredSymbols = new DiscoveredSymbols([$namespace, new ClassSymbol('Acme\Thing', $file, $namespace)]);

        $sut = $this->getSut($this->getConfig(true, $vendorDirectory), $filesystem, $discoveredSymbols);

        $result = $sut->getDiscoveredSymbolsWithComposerRuntime();

        $installedVersions = $result->getClass('Composer\InstalledVersions');
        self::assertNotNull($installedVersions);
        self::assertTrue($installedVersions->isDoRename());
        self::assertSame('Prefix\Composer\InstalledVersions', $installedVersions->getReplacementFqdnName());
        self::assertNotNull($result->getNamespace('Composer'));

        // The generated autoloader class is only used by Composer's own files.
        self::assertNull($result->getClass('ComposerAutoloaderInitabc123'));

        // Still present.
        self::assertNotNull($result->getClass('Acme\Thing'));

        // The command's own collection is untouched.
        self::assertNull($discoveredSymbols->getClass('Composer\InstalledVersions'));
        self::assertNull($discoveredSymbols->getNamespace('Composer'));
    }

    /**
     * Without the classmap output, no prefixed copy of Composer's files is generated, so references must be left
     * pointing at the real classes.
     *
     * @covers ::getDiscoveredSymbolsWithComposerRuntime
     */
    public function test_no_runtime_symbols_without_classmap_output(): void
    {
        $discoveredSymbols = new DiscoveredSymbols([new NamespaceSymbol('Acme')]);

        $sut = $this->getSut($this->getConfig(false, '/vendor'), $this->getInMemoryFileSystem(), $discoveredSymbols);

        self::assertSame($discoveredSymbols, $sut->getDiscoveredSymbolsWithComposerRuntime());
    }
}
