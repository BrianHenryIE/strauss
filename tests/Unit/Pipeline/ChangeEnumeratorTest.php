<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Config\ChangeEnumeratorConfigInterface;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\EnumSymbol;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Mockery;
use Mockery\MockInterface;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\ChangeEnumerator
 */
class ChangeEnumeratorTest extends TestCase
{
    /**
     * @covers ::determineReplacements
     */
    public function testFunctionReplacement(): void
    {
        /** @var MockInterface&ChangeEnumeratorConfigInterface $config */
        $config = Mockery::mock(ChangeEnumeratorConfigInterface::class);
        $config->expects('getClassmapPrefix')->andReturn('Class_Prefix_');
        $config->expects('getFunctionsPrefix')->andReturn('functions_prefix_')->atLeast()->once();
        $config->allows('getConstantsPrefix')->andReturnNull();
        $config->allows('getExcludeFunctionsFromStringRenaming')->andReturn([]);

        $sut = new ChangeEnumerator($config, $this->getTestLogger());

        $discoveredSymbols = new DiscoveredSymbols();
        $symbol = new FunctionSymbol(
            'myFunction',
            new File(
                '/path/to/file.php',
                'file.php',
                '/destination-path/to/file.php'
            ),
            new NamespaceSymbol('\\')
        );
        $symbol->setDoRename(true);

        $discoveredSymbols->add($symbol);

        $sut->determineReplacements($discoveredSymbols);

        $this->assertEquals(
            'functions_prefix_myFunction',
            $symbol->getLocalReplacement()
        );
    }

    /**
     * A namespaced enum gets its replacement FQDN from the namespace replacement, same as classes.
     *
     * @covers ::determineReplacements
     */
    public function testNamespacedEnumReplacement(): void
    {
        /** @var MockInterface&ChangeEnumeratorConfigInterface $config */
        $config = Mockery::mock(ChangeEnumeratorConfigInterface::class);
        $config->allows('getNamespacePrefix')->andReturn('Prefix');
        $config->allows('getNamespaceReplacementPatterns')->andReturn([]);
        $config->allows('getExcludeNamespacesFromPrefixing')->andReturn([]);
        $config->allows('getClassmapPrefix')->andReturn('Class_Prefix_');
        $config->allows('getFunctionsPrefix')->andReturnNull();
        $config->allows('getConstantsPrefix')->andReturnNull();
        $config->allows('getExcludeFunctionsFromStringRenaming')->andReturn([]);

        $sut = new ChangeEnumerator($config, $this->getTestLogger());

        $file = new File(
            '/path/to/file.php',
            'file.php',
            '/destination-path/to/file.php'
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $namespaceSymbol = new NamespaceSymbol('My\NS', $file);
        $namespaceSymbol->setDoRename(true);

        $discoveredSymbols->add($namespaceSymbol);

        $enumSymbol = new EnumSymbol('My\NS\Status', $file, $namespaceSymbol, 'string');
        $enumSymbol->setDoRename(true);

        $discoveredSymbols->add($enumSymbol);

        $sut->determineReplacements($discoveredSymbols);

        $this->assertEquals(
            'Prefix\My\NS\Status',
            $enumSymbol->getReplacementFqdnName()
        );
    }

    /**
     * A global enum gets the classmap prefix, same as global classes.
     *
     * @covers ::determineReplacements
     */
    public function testGlobalEnumReplacement(): void
    {
        /** @var MockInterface&ChangeEnumeratorConfigInterface $config */
        $config = Mockery::mock(ChangeEnumeratorConfigInterface::class);
        $config->allows('getNamespacePrefix')->andReturn('Prefix');
        $config->allows('getNamespaceReplacementPatterns')->andReturn([]);
        $config->allows('getExcludeNamespacesFromPrefixing')->andReturn([]);
        $config->allows('getClassmapPrefix')->andReturn('Class_Prefix_');
        $config->allows('getFunctionsPrefix')->andReturnNull();
        $config->allows('getConstantsPrefix')->andReturnNull();
        $config->allows('getExcludeFunctionsFromStringRenaming')->andReturn([]);

        $sut = new ChangeEnumerator($config, $this->getTestLogger());

        $file = new File(
            '/path/to/file.php',
            'file.php',
            '/destination-path/to/file.php'
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $enumSymbol = new EnumSymbol('GlobalSuit', $file, new NamespaceSymbol('\\'));
        $enumSymbol->setDoRename(true);

        $discoveredSymbols->add($enumSymbol);

        $sut->determineReplacements($discoveredSymbols);

        $this->assertEquals(
            'Class_Prefix_GlobalSuit',
            $enumSymbol->getReplacementFqdnName()
        );
    }

    /**
     * An already-prefixed global enum is not prefixed twice.
     *
     * @covers ::determineReplacements
     */
    public function testGlobalEnumAlreadyPrefixedIsSkipped(): void
    {
        /** @var MockInterface&ChangeEnumeratorConfigInterface $config */
        $config = Mockery::mock(ChangeEnumeratorConfigInterface::class);
        $config->allows('getNamespacePrefix')->andReturn('Prefix');
        $config->allows('getNamespaceReplacementPatterns')->andReturn([]);
        $config->allows('getExcludeNamespacesFromPrefixing')->andReturn([]);
        $config->allows('getClassmapPrefix')->andReturn('Class_Prefix_');
        $config->allows('getFunctionsPrefix')->andReturnNull();
        $config->allows('getConstantsPrefix')->andReturnNull();
        $config->allows('getExcludeFunctionsFromStringRenaming')->andReturn([]);
        $config->allows('isTargetDirectoryVendor')->andReturnFalse();

        $sut = new ChangeEnumerator($config, $this->getTestLogger());

        $file = new File(
            '/path/to/file.php',
            'file.php',
            '/destination-path/to/file.php'
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $enumSymbol = new EnumSymbol('Class_Prefix_GlobalSuit', $file, new NamespaceSymbol('\\'));
        $discoveredSymbols->add($enumSymbol);

        $sut->determineReplacements($discoveredSymbols);

        $this->assertEquals(
            'Class_Prefix_GlobalSuit',
            $enumSymbol->getReplacementFqdnName()
        );
    }

    /**
     * Functions listed in `exclude_from_string_rename.functions` are still prefixed where called,
     * but are flagged so the Prefixer does not rename occurrences inside strings, e.g. `$array['value']`.
     *
     * @covers ::determineReplacements
     */
    public function testExcludeFunctionsFromStringRenaming(): void
    {
        /** @var MockInterface&ChangeEnumeratorConfigInterface $config */
        $config = Mockery::mock(ChangeEnumeratorConfigInterface::class);
        $config->expects('getClassmapPrefix')->andReturn('Class_Prefix_');
        $config->allows('getFunctionsPrefix')->andReturn('functions_prefix_');
        $config->allows('getConstantsPrefix')->andReturnNull();
        $config->expects('getExcludeFunctionsFromStringRenaming')->andReturn(['value']);

        $sut = new ChangeEnumerator($config, $this->getTestLogger());

        $file = new File('/path/to/file.php', 'file.php', '/destination-path/to/file.php');
        $globalNamespace = new NamespaceSymbol('\\');

        $excludedSymbol = new FunctionSymbol('value', $file, $globalNamespace);
        $excludedSymbol->setDoRename(true);

        $otherSymbol = new FunctionSymbol('other_fn', $file, $globalNamespace);
        $otherSymbol->setDoRename(true);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($excludedSymbol);
        $discoveredSymbols->add($otherSymbol);

        $sut->determineReplacements($discoveredSymbols);

        $this->assertEquals('functions_prefix_value', $excludedSymbol->getLocalReplacement());
        $this->assertFalse($excludedSymbol->isReplaceInString());

        $this->assertEquals('functions_prefix_other_fn', $otherSymbol->getLocalReplacement());
        $this->assertTrue($otherSymbol->isReplaceInString());
    }

    /**
     * Prefixing in place a second time: the package's `autoload` key gives `Psr\Log`, its files already declare
     * `Acme\Log`. The pattern no longer matches `Acme\Log`, so without the guard the default `namespace_prefix` would
     * rename it again.
     *
     * @covers ::determineReplacements
     * @covers ::keepAlreadyRenamedNamespaces
     */
    public function testAlreadyRenamedNamespaceIsNotRenamedAgain(): void
    {
        /** @var MockInterface&ChangeEnumeratorConfigInterface $config */
        $config = Mockery::mock(ChangeEnumeratorConfigInterface::class);
        $config->allows('getNamespacePrefix')->andReturn('Default\Prefix');
        $config->allows('getNamespaceReplacementPatterns')->andReturn(['~Psr\\\\Log~' => 'Acme\\Log']);
        $config->allows('getExcludeNamespacesFromPrefixing')->andReturn([]);
        $config->allows('isTargetDirectoryVendor')->andReturnTrue();
        $config->allows('getClassmapPrefix')->andReturnNull();
        $config->allows('getFunctionsPrefix')->andReturnNull();
        $config->allows('getConstantsPrefix')->andReturnNull();
        $config->allows('getExcludeFunctionsFromStringRenaming')->andReturn([]);

        $sut = new ChangeEnumerator($config, $this->getTestLogger());

        $file = new File('/vendor/psr/log/src/LoggerInterface.php', 'psr/log/src/LoggerInterface.php', '/vendor/psr/log/src/LoggerInterface.php');

        $autoloadNamespace = new NamespaceSymbol('Psr\Log');
        $autoloadNamespace->setDoRename(true);

        $renamedNamespace = new NamespaceSymbol('Acme\Log', $file);
        $renamedNamespace->setDoRename(true);

        $renamedSubNamespace = new NamespaceSymbol('Acme\Log\Test', $file);
        $renamedSubNamespace->setDoRename(true);

        $otherNamespace = new NamespaceSymbol('Other\Package', $file);
        $otherNamespace->setDoRename(true);

        $discoveredSymbols = new DiscoveredSymbols();
        foreach ([$autoloadNamespace, $renamedNamespace, $renamedSubNamespace, $otherNamespace] as $namespace) {
            $discoveredSymbols->add($namespace);
        }

        $sut->determineReplacements($discoveredSymbols);

        $this->assertSame('Acme\Log', $autoloadNamespace->getReplacementFqdnName());
        $this->assertSame('Acme\Log', $renamedNamespace->getReplacementFqdnName());
        $this->assertSame('Acme\Log\Test', $renamedSubNamespace->getReplacementFqdnName());
        $this->assertSame('Default\Prefix\Other\Package', $otherNamespace->getReplacementFqdnName());

        // Their names before renaming, so uses of those are updated.
        $this->assertNull($autoloadNamespace->getInverseReplacement());
        $this->assertSame('Psr\Log', $renamedNamespace->getInverseReplacement());
        $this->assertSame('Psr\Log\Test', $renamedSubNamespace->getInverseReplacement());
        $this->assertNull($otherNamespace->getInverseReplacement());
    }

    /**
     * @param array<string,string> $namespaceReplacementPatterns
     *
     * @return MockInterface&ChangeEnumeratorConfigInterface
     */
    protected function getNamespaceConfig(
        ?string $namespacePrefix,
        array $namespaceReplacementPatterns = [],
        bool $isTargetDirectoryVendor = true
    ): ChangeEnumeratorConfigInterface {
        /** @var MockInterface&ChangeEnumeratorConfigInterface $config */
        $config = Mockery::mock(ChangeEnumeratorConfigInterface::class);
        $config->allows('getNamespacePrefix')->andReturn($namespacePrefix);
        $config->allows('getNamespaceReplacementPatterns')->andReturn($namespaceReplacementPatterns);
        $config->allows('getExcludeNamespacesFromPrefixing')->andReturn([]);
        $config->allows('isTargetDirectoryVendor')->andReturn($isTargetDirectoryVendor);
        $config->allows('getClassmapPrefix')->andReturnNull();
        $config->allows('getFunctionsPrefix')->andReturnNull();
        $config->allows('getConstantsPrefix')->andReturnNull();
        $config->allows('getExcludeFunctionsFromStringRenaming')->andReturn([]);

        return $config;
    }

    /**
     * @param string[] $namespaceNames
     *
     * @return array<string, NamespaceSymbol>
     */
    protected function getNamespacesToRename(array $namespaceNames): array
    {
        $file = new File('/vendor/psr/log/src/LoggerInterface.php', 'psr/log/src/LoggerInterface.php', '/vendor/psr/log/src/LoggerInterface.php');

        $namespaces = [];
        foreach ($namespaceNames as $namespaceName) {
            $namespaces[$namespaceName] = new NamespaceSymbol($namespaceName, $file);
            $namespaces[$namespaceName]->setDoRename(true);
        }

        return $namespaces;
    }

    /**
     * Found by renaming the namespace from the package's `autoload` key forwards, so a pattern's back-references
     * need not be reversed.
     *
     * @covers ::determineReplacements
     * @covers ::keepAlreadyRenamedNamespaces
     */
    public function testInverseReplacementWithBackReferencePattern(): void
    {
        $sut = new ChangeEnumerator(
            $this->getNamespaceConfig('Default\Prefix', ['~Psr\\\\(.*)~' => 'Acme\\Psr\\\\$1']),
            $this->getTestLogger()
        );

        $namespaces = $this->getNamespacesToRename(['Psr\Log', 'Acme\Psr\Log', 'Acme\Psr\Log\Test']);

        $sut->determineReplacements(new DiscoveredSymbols(array_values($namespaces)));

        $this->assertSame('Acme\Psr\Log', $namespaces['Psr\Log']->getReplacementFqdnName());
        $this->assertNull($namespaces['Psr\Log']->getInverseReplacement());

        // Not `Acme\Psr\Acme\Psr\Log`.
        $this->assertSame('Acme\Psr\Log', $namespaces['Acme\Psr\Log']->getReplacementFqdnName());
        $this->assertSame('Psr\Log', $namespaces['Acme\Psr\Log']->getInverseReplacement());

        $this->assertSame('Acme\Psr\Log\Test', $namespaces['Acme\Psr\Log\Test']->getReplacementFqdnName());
        $this->assertSame('Psr\Log\Test', $namespaces['Acme\Psr\Log\Test']->getInverseReplacement());
    }

    /**
     * A namespace not in its package's `autoload` key, e.g. only autoloaded by `classmap`, which already begins with
     * `namespace_prefix`.
     *
     * @covers ::determineReplacements
     */
    public function testInverseReplacementByRemovingNamespacePrefix(): void
    {
        $sut = new ChangeEnumerator($this->getNamespaceConfig('Prefix\\'), $this->getTestLogger());

        $namespaces = $this->getNamespacesToRename(['Prefix\Psr\Log', 'PrefixOther\Package', 'Other\Package']);

        $sut->determineReplacements(new DiscoveredSymbols(array_values($namespaces)));

        $this->assertSame('Prefix\Psr\Log', $namespaces['Prefix\Psr\Log']->getReplacementFqdnName());
        $this->assertSame('Psr\Log', $namespaces['Prefix\Psr\Log']->getInverseReplacement());

        // Only begins with the same characters.
        $this->assertNull($namespaces['PrefixOther\Package']->getInverseReplacement());

        // Being renamed now.
        $this->assertSame('Prefix\Other\Package', $namespaces['Other\Package']->getReplacementFqdnName());
        $this->assertNull($namespaces['Other\Package']->getInverseReplacement());
    }

    /**
     * A namespace which begins with `namespace_prefix` but is renamed now by a pattern was not renamed before.
     *
     * @covers ::determineReplacements
     */
    public function testNoInverseReplacementWhenRenamedByPattern(): void
    {
        $sut = new ChangeEnumerator(
            $this->getNamespaceConfig('Prefix', ['~Prefix\\\\Psr~' => 'Other\\Psr']),
            $this->getTestLogger()
        );

        $namespaces = $this->getNamespacesToRename(['Prefix\Psr\Log']);

        $sut->determineReplacements(new DiscoveredSymbols(array_values($namespaces)));

        $this->assertSame('Other\Psr\Log', $namespaces['Prefix\Psr\Log']->getReplacementFqdnName());
        $this->assertNull($namespaces['Prefix\Psr\Log']->getInverseReplacement());
    }

    /**
     * With another target directory, the packages in `vendor` are never renamed, so a namespace which begins with
     * the prefix, or with another's replacement, is the package's own.
     *
     * @covers ::determineReplacements
     * @covers ::keepAlreadyRenamedNamespaces
     */
    public function testNoInverseReplacementWhenTargetDirectoryIsNotVendor(): void
    {
        $sut = new ChangeEnumerator(
            $this->getNamespaceConfig('Prefix', ['~Psr\\\\Log~' => 'Acme\\Log'], false),
            $this->getTestLogger()
        );

        $namespaces = $this->getNamespacesToRename(['Psr\Log', 'Acme\Log', 'Prefix\Other']);

        $sut->determineReplacements(new DiscoveredSymbols(array_values($namespaces)));

        foreach ($namespaces as $namespace) {
            $this->assertNull($namespace->getInverseReplacement(), $namespace->getOriginalFqdnName());
        }
    }

    /**
     * @return MockInterface&ChangeEnumeratorConfigInterface
     */
    protected function getGlobalPrefixesConfig(bool $isTargetDirectoryVendor): ChangeEnumeratorConfigInterface
    {
        /** @var MockInterface&ChangeEnumeratorConfigInterface $config */
        $config = Mockery::mock(ChangeEnumeratorConfigInterface::class);
        $config->allows('getNamespacePrefix')->andReturn('Prefix');
        $config->allows('getNamespaceReplacementPatterns')->andReturn([]);
        $config->allows('getExcludeNamespacesFromPrefixing')->andReturn([]);
        $config->allows('isTargetDirectoryVendor')->andReturn($isTargetDirectoryVendor);
        $config->allows('getClassmapPrefix')->andReturn('Prefix_');
        $config->allows('getFunctionsPrefix')->andReturn('prefix_');
        $config->allows('getConstantsPrefix')->andReturn('PREFIX_');
        $config->allows('getExcludeFunctionsFromStringRenaming')->andReturn([]);

        return $config;
    }

    /**
     * A global class, function and constant renamed by an earlier run in place.
     *
     * @return array{0:ClassSymbol, 1:FunctionSymbol, 2:ConstantSymbol}
     */
    protected function getAlreadyPrefixedGlobalSymbols(): array
    {
        $file = new File('/path/to/file.php', 'file.php', '/destination-path/to/file.php');
        $globalNamespace = new NamespaceSymbol('\\');

        $symbols = [
            new ClassSymbol('Prefix_Thing', $file, $globalNamespace),
            new FunctionSymbol('prefix_helper', $file, $globalNamespace),
            new ConstantSymbol('PREFIX_VERSION', $file, $globalNamespace),
        ];
        foreach ($symbols as $symbol) {
            $symbol->setDoRename(true);
        }

        return $symbols;
    }

    /**
     * When prefixing in place, already-prefixed global symbols keep their names, and their names before the earlier
     * run are kept so uses of them can be updated.
     *
     * @covers ::determineReplacements
     */
    public function testAlreadyPrefixedGlobalSymbolsInverseReplacement(): void
    {
        $sut = new ChangeEnumerator($this->getGlobalPrefixesConfig(true), $this->getTestLogger());

        [$classSymbol, $functionSymbol, $constantSymbol] = $this->getAlreadyPrefixedGlobalSymbols();

        $sut->determineReplacements(new DiscoveredSymbols([$classSymbol, $functionSymbol, $constantSymbol]));

        $this->assertSame('Prefix_Thing', $classSymbol->getReplacementFqdnName());
        $this->assertSame('Thing', $classSymbol->getInverseReplacement());

        $this->assertSame('prefix_helper', $functionSymbol->getReplacementFqdnName());
        $this->assertSame('helper', $functionSymbol->getInverseReplacement());

        $this->assertSame('PREFIX_VERSION', $constantSymbol->getReplacementFqdnName());
        $this->assertSame('VERSION', $constantSymbol->getInverseReplacement());
    }

    /**
     * Not in place, the files are read fresh from `vendor` on each run, so no inverse replacement is needed.
     *
     * @covers ::determineReplacements
     */
    public function testAlreadyPrefixedGlobalSymbolsNoInverseReplacementWhenTargetDirectoryIsNotVendor(): void
    {
        $sut = new ChangeEnumerator($this->getGlobalPrefixesConfig(false), $this->getTestLogger());

        $symbols = $this->getAlreadyPrefixedGlobalSymbols();

        $sut->determineReplacements(new DiscoveredSymbols($symbols));

        foreach ($symbols as $symbol) {
            $this->assertSame($symbol->getOriginalFqdnName(), $symbol->getReplacementFqdnName());
            $this->assertNull($symbol->getInverseReplacement(), $symbol->getOriginalFqdnName());
        }
    }

    /**
     * Global symbols not already prefixed are prefixed, with no inverse replacement.
     *
     * @covers ::determineReplacements
     */
    public function testGlobalSymbolsPrefixedWithoutInverseReplacement(): void
    {
        $sut = new ChangeEnumerator($this->getGlobalPrefixesConfig(true), $this->getTestLogger());

        $file = new File('/path/to/file.php', 'file.php', '/destination-path/to/file.php');
        $globalNamespace = new NamespaceSymbol('\\');
        $classSymbol = new ClassSymbol('Thing', $file, $globalNamespace);
        $functionSymbol = new FunctionSymbol('helper', $file, $globalNamespace);
        $constantSymbol = new ConstantSymbol('VERSION', $file, $globalNamespace);
        foreach ([$classSymbol, $functionSymbol, $constantSymbol] as $symbol) {
            $symbol->setDoRename(true);
        }

        $sut->determineReplacements(new DiscoveredSymbols([$classSymbol, $functionSymbol, $constantSymbol]));

        $this->assertSame('Prefix_Thing', $classSymbol->getReplacementFqdnName());
        $this->assertSame('prefix_helper', $functionSymbol->getReplacementFqdnName());
        $this->assertSame('PREFIX_VERSION', $constantSymbol->getReplacementFqdnName());

        foreach ([$classSymbol, $functionSymbol, $constantSymbol] as $symbol) {
            $this->assertNull($symbol->getInverseReplacement(), $symbol->getOriginalFqdnName());
        }
    }
}
