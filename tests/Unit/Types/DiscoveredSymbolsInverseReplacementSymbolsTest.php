<?php

/**
 * Prefixing in place after `composer update other/package`: psr/log was renamed by an earlier run and not reinstalled,
 * so it already declares `Prefix\Psr\Log`, while packages installed since use `Psr\Log`.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/343
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\TestCase;
use Mockery;
use Mockery\MockInterface;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Types\DiscoveredSymbols
 */
class DiscoveredSymbolsInverseReplacementSymbolsTest extends TestCase
{
    /**
     * @return ComposerPackage&MockInterface
     */
    protected function getPackage(string $packageName): ComposerPackage
    {
        $package = Mockery::mock(ComposerPackage::class);
        $package->shouldReceive('getPackageName')->andReturn($packageName);
        $package->shouldReceive('getPackageAbsolutePath')->andReturn('/project/vendor/' . $packageName);
        $package->shouldReceive('addFile');
        $package->shouldReceive('isPsr0Autoloaded')->andReturnFalse();
        $package->shouldReceive('__toString')->andReturn($packageName);

        return $package;
    }

    protected function getFile(ComposerPackage $package, string $packageRelativePath): FileWithDependency
    {
        return new FileWithDependency(
            $package,
            $package->getPackageName() . '/' . $packageRelativePath,
            '/project/vendor/' . $package->getPackageName() . '/' . $packageRelativePath,
            '/project/vendor/' . $package->getPackageName() . '/' . $packageRelativePath
        );
    }

    /**
     * psr/log as {@see \BrianHenryIE\Strauss\Pipeline\ChangeEnumerator} leaves it: renamed to itself, with its name
     * before renaming.
     *
     * @return array{0:DiscoveredSymbols, 1:NamespaceSymbol, 2:FileWithDependency}
     */
    protected function getAlreadyPrefixedPsrLog(string $packageName = 'psr/log'): array
    {
        $package = $this->getPackage($packageName);
        $file = $this->getFile($package, 'src/LoggerInterface.php');

        $namespace = new NamespaceSymbol('Prefix\Psr\Log', $file);
        $namespace->setDoRename(true);
        $namespace->setLocalReplacement('Prefix\Psr\Log');
        $namespace->setInverseReplacement('Psr\Log');

        $loggerInterface = new InterfaceSymbol('Prefix\Psr\Log\LoggerInterface', $file, $namespace);
        $loggerInterface->setDoRename(true);

        $nullLogger = new ClassSymbol('Prefix\Psr\Log\NullLogger', $this->getFile($package, 'src/NullLogger.php'), $namespace);
        $nullLogger->setDoRename(true);

        return [new DiscoveredSymbols([$namespace, $loggerInterface, $nullLogger]), $namespace, $file];
    }

    /**
     * @param DiscoveredSymbol[] $symbols
     *
     * @return array<string, string> Each symbol's class and original name => its replacement.
     */
    protected function getRenames(array $symbols): array
    {
        $renames = [];
        foreach ($symbols as $symbol) {
            $renames[get_class($symbol) . ' ' . $symbol->getOriginalFqdnName()] = $symbol->getReplacementFqdnName();
        }
        ksort($renames);

        return $renames;
    }

    /**
     * When the namespace before renaming was not discovered, e.g. from the package's `autoload` key, it is created.
     *
     * @covers ::getInverseReplacementSymbols
     * @covers ::getInverseReplacementNamespace
     */
    public function test_inverse_replacement_symbols_are_renamed_to_the_current_names(): void
    {
        [$sut] = $this->getAlreadyPrefixedPsrLog();

        $result = $sut->getInverseReplacementSymbols(['psr/log']);

        self::assertSame(
            [
                ClassSymbol::class . ' Psr\Log\NullLogger' => 'Prefix\Psr\Log\NullLogger',
                InterfaceSymbol::class . ' Psr\Log\LoggerInterface' => 'Prefix\Psr\Log\LoggerInterface',
                NamespaceSymbol::class . ' Psr\Log' => 'Prefix\Psr\Log',
            ],
            $this->getRenames($result)
        );

        foreach ($result as $symbol) {
            self::assertTrue($symbol->isDoRename());
            self::assertSame('psr/log', $symbol->getPackageName());
        }
    }

    /**
     * The copies are only for planning: neither they nor their namespaces are added to the files' symbols, and the
     * discovered symbols are unchanged.
     *
     * @covers ::getInverseReplacementSymbols
     * @covers ::getInverseReplacementNamespace
     */
    public function test_discovered_symbols_and_files_are_unchanged(): void
    {
        [$sut, $namespace, $file] = $this->getAlreadyPrefixedPsrLog();
        $fileSymbolsBefore = $file->getDiscoveredSymbols();

        $sut->getInverseReplacementSymbols(['psr/log']);

        self::assertSame($fileSymbolsBefore, $file->getDiscoveredSymbols());
        self::assertNull($sut->getNamespace('Psr\Log'));
        self::assertNull($sut->getInterface('Psr\Log\LoggerInterface'));
        self::assertSame('Prefix\Psr\Log\LoggerInterface', (string) $sut->getInterface('Prefix\Psr\Log\LoggerInterface'));
        self::assertSame($namespace, $sut->getNamespace('Prefix\Psr\Log'));
    }

    /**
     * E.g. the namespace from the package's `autoload.psr-4` key, renamed to the current namespace.
     *
     * @covers ::getInverseReplacementNamespace
     */
    public function test_discovered_inverse_replacement_namespace_is_reused(): void
    {
        [$sut] = $this->getAlreadyPrefixedPsrLog();

        $autoloadNamespace = new NamespaceSymbol('Psr\Log');
        $autoloadNamespace->setDoRename(true);
        $autoloadNamespace->setLocalReplacement('Prefix\Psr\Log');
        $sut->add($autoloadNamespace);

        $result = $sut->getInverseReplacementSymbols(['psr/log']);

        self::assertCount(2, $result);
        foreach ($result as $symbol) {
            self::assertInstanceOf(NamespacedSymbol::class, $symbol);
            self::assertSame($autoloadNamespace, $symbol->getNamespace());
        }
    }

    /**
     * @covers ::getInverseReplacementNamespace
     */
    public function test_discovered_namespace_renamed_to_something_else_is_left_alone(): void
    {
        [$sut] = $this->getAlreadyPrefixedPsrLog();

        $otherNamespace = new NamespaceSymbol('Psr\Log');
        $otherNamespace->setDoRename(true);
        $otherNamespace->setLocalReplacement('Other\Psr\Log');
        $sut->add($otherNamespace);

        self::assertSame([], $sut->getInverseReplacementSymbols(['psr/log']));
    }

    /**
     * E.g. the package was reinstalled, so its symbols with the original names were discovered too.
     *
     * @covers ::getInverseReplacementSymbols
     */
    public function test_discovered_inverse_replacement_symbol_is_not_copied(): void
    {
        [$sut, , $file] = $this->getAlreadyPrefixedPsrLog();

        $autoloadNamespace = new NamespaceSymbol('Psr\Log');
        $autoloadNamespace->setDoRename(true);
        $autoloadNamespace->setLocalReplacement('Prefix\Psr\Log');
        $sut->add($autoloadNamespace);

        $loggerInterface = new InterfaceSymbol('Psr\Log\LoggerInterface', $file, $autoloadNamespace);
        $loggerInterface->setDoRename(true);
        $sut->add($loggerInterface);

        self::assertSame(
            [ClassSymbol::class . ' Psr\Log\NullLogger' => 'Prefix\Psr\Log\NullLogger'],
            $this->getRenames($sut->getInverseReplacementSymbols(['psr/log']))
        );
    }

    /**
     * @covers ::getInverseReplacementSymbols
     */
    public function test_packages_not_being_prefixed_are_ignored(): void
    {
        [$sut] = $this->getAlreadyPrefixedPsrLog('other/package');

        self::assertSame([], $sut->getInverseReplacementSymbols(['psr/log']));
    }

    /**
     * @covers ::getInverseReplacementSymbols
     */
    public function test_symbols_not_to_rename_are_ignored(): void
    {
        [$sut] = $this->getAlreadyPrefixedPsrLog();

        foreach ($sut->getNamespacedSymbols()->toArray() as $symbol) {
            $symbol->setDoRename(false);
        }

        self::assertSame([], $sut->getInverseReplacementSymbols(['psr/log']));
    }

    /**
     * Without a name before renaming, e.g. a namespace renamed now, or not prefixing in place.
     *
     * @covers ::getInverseReplacementSymbols
     */
    public function test_namespace_without_inverse_replacement_is_ignored(): void
    {
        [$sut, $namespace] = $this->getAlreadyPrefixedPsrLog();

        $namespace->setInverseReplacement(null);

        self::assertSame([], $sut->getInverseReplacementSymbols(['psr/log']));
    }

    /**
     * Each type of namespaced symbol is copied as the same type.
     *
     * @covers ::getInverseReplacementSymbols
     */
    public function test_each_type_is_copied(): void
    {
        [$sut, $namespace, $file] = $this->getAlreadyPrefixedPsrLog();

        $symbols = [
            new TraitSymbol('Prefix\Psr\Log\LoggerTrait', $file, $namespace),
            new EnumSymbol('Prefix\Psr\Log\Level', $file, $namespace, 'string'),
            new FunctionSymbol('Prefix\Psr\Log\log', $file, $namespace),
            new ConstantSymbol('Prefix\Psr\Log\LEVEL', $file, $namespace),
        ];
        foreach ($symbols as $symbol) {
            $symbol->setDoRename(true);
            $sut->add($symbol);
        }

        $result = $sut->getInverseReplacementSymbols(['psr/log']);

        self::assertSame(
            [
                ClassSymbol::class . ' Psr\Log\NullLogger' => 'Prefix\Psr\Log\NullLogger',
                ConstantSymbol::class . ' Psr\Log\LEVEL' => 'Prefix\Psr\Log\LEVEL',
                EnumSymbol::class . ' Psr\Log\Level' => 'Prefix\Psr\Log\Level',
                FunctionSymbol::class . ' Psr\Log\log' => 'Prefix\Psr\Log\log',
                InterfaceSymbol::class . ' Psr\Log\LoggerInterface' => 'Prefix\Psr\Log\LoggerInterface',
                NamespaceSymbol::class . ' Psr\Log' => 'Prefix\Psr\Log',
                TraitSymbol::class . ' Psr\Log\LoggerTrait' => 'Prefix\Psr\Log\LoggerTrait',
            ],
            $this->getRenames($result)
        );

        $enum = array_values(array_filter($result, fn($symbol) => $symbol instanceof EnumSymbol))[0];
        self::assertSame('string', $enum->getBackingType());
    }

    /**
     * A namespace within the renamed one gets its own inverse replacement namespace, e.g. `Prefix\Psr\Log\Test` was
     * `Psr\Log\Test`.
     *
     * @covers ::getInverseReplacementSymbols
     * @covers ::getInverseReplacementNamespace
     */
    public function test_sub_namespace(): void
    {
        [$sut, , $file] = $this->getAlreadyPrefixedPsrLog();

        $subNamespace = new NamespaceSymbol('Prefix\Psr\Log\Test', $file);
        $subNamespace->setDoRename(true);
        $subNamespace->setLocalReplacement('Prefix\Psr\Log\Test');
        $subNamespace->setInverseReplacement('Psr\Log\Test');
        $sut->add($subNamespace);

        $testLogger = new ClassSymbol('Prefix\Psr\Log\Test\TestLogger', $file, $subNamespace);
        $testLogger->setDoRename(true);
        $sut->add($testLogger);

        $renames = $this->getRenames($sut->getInverseReplacementSymbols(['psr/log']));

        self::assertSame('Prefix\Psr\Log\Test\TestLogger', $renames[ClassSymbol::class . ' Psr\Log\Test\TestLogger']);
        self::assertSame('Prefix\Psr\Log\Test', $renames[NamespaceSymbol::class . ' Psr\Log\Test']);
        self::assertSame('Prefix\Psr\Log', $renames[NamespaceSymbol::class . ' Psr\Log']);
    }
}
