<?php

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\AlreadyPrefixedSymbolsConfigInterface;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\InterfaceSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Mockery;
use Mockery\MockInterface;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\AlreadyPrefixedSymbols
 */
class AlreadyPrefixedSymbolsTest extends TestCase
{
    /**
     * @param array<string,ComposerPackage> $packagesToPrefix
     *
     * @return AlreadyPrefixedSymbolsConfigInterface&MockInterface
     */
    protected function getConfig(array $packagesToPrefix, bool $isTargetDirectoryVendor = true, ?string $namespacePrefix = 'Prefix\\'): AlreadyPrefixedSymbolsConfigInterface
    {
        $config = Mockery::mock(AlreadyPrefixedSymbolsConfigInterface::class);
        $config->shouldReceive('isTargetDirectoryVendor')->andReturn($isTargetDirectoryVendor);
        $config->shouldReceive('getNamespacePrefix')->andReturn($namespacePrefix);
        $config->shouldReceive('getPackagesToPrefix')->andReturn($packagesToPrefix);

        return $config;
    }

    /**
     * @return ComposerPackage&MockInterface
     */
    protected function getPackage(string $packageName): ComposerPackage
    {
        $package = Mockery::mock(ComposerPackage::class);
        $package->shouldReceive('getPackageName')->andReturn($packageName);
        $package->shouldReceive('getPackageAbsolutePath')->andReturn('/project/vendor/' . $packageName);
        $package->shouldReceive('addFile');
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
     * psr/log already declares `Prefix\Psr\Log`, so its symbols are renamed to themselves, see
     * {@see ChangeEnumerator::determineReplacements()}.
     *
     * @return array{0:DiscoveredSymbols, 1:ComposerPackage, 2:NamespaceSymbol}
     */
    protected function getAlreadyPrefixedPsrLog(): array
    {
        $package = $this->getPackage('psr/log');

        $loggerInterfaceFile = $this->getFile($package, 'src/LoggerInterface.php');
        $prefixedNamespace = new NamespaceSymbol('Prefix\Psr\Log', $loggerInterfaceFile);
        $prefixedNamespace->setDoRename(true);

        $loggerInterface = new InterfaceSymbol('Prefix\Psr\Log\LoggerInterface', $loggerInterfaceFile, $prefixedNamespace);
        $loggerInterface->setDoRename(true);

        $nullLogger = new ClassSymbol('Prefix\Psr\Log\NullLogger', $this->getFile($package, 'src/NullLogger.php'), $prefixedNamespace);
        $nullLogger->setDoRename(true);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($prefixedNamespace);
        $discoveredSymbols->add($loggerInterface);
        $discoveredSymbols->add($nullLogger);

        return [$discoveredSymbols, $package, $prefixedNamespace];
    }

    /**
     * When the package's `autoload` key did not provide the original namespace, it is created.
     *
     * @covers ::getOriginalSymbols
     * @covers ::getOriginalNamespace
     * @covers ::createOriginalSymbol
     * @covers ::getSourceFile
     */
    public function test_original_symbols_are_renamed_to_the_prefixed_names(): void
    {
        [$discoveredSymbols, $package] = $this->getAlreadyPrefixedPsrLog();

        $sut = new AlreadyPrefixedSymbols($this->getConfig(['psr/log' => $package]), $this->getLogger());

        $result = $sut->getOriginalSymbols($discoveredSymbols);

        $originalNamespace = $result->getNamespace('Psr\Log');
        self::assertNotNull($originalNamespace);
        self::assertTrue($originalNamespace->isDoRename());
        self::assertSame('Prefix\Psr\Log', $originalNamespace->getReplacementFqdnName());

        $loggerInterface = $result->getInterface('Psr\Log\LoggerInterface');
        self::assertInstanceOf(InterfaceSymbol::class, $loggerInterface);
        self::assertTrue($loggerInterface->isDoRename());
        self::assertSame('Prefix\Psr\Log\LoggerInterface', $loggerInterface->getReplacementFqdnName());

        $nullLogger = $result->getClass('Psr\Log\NullLogger');
        self::assertInstanceOf(ClassSymbol::class, $nullLogger);
        self::assertSame('Prefix\Psr\Log\NullLogger', $nullLogger->getReplacementFqdnName());

        self::assertTrue($this->getTestLogger()->hasInfoThatContains('Already prefixed'));
    }

    /**
     * E.g. the namespace from the package's `autoload.psr-4` key, already renamed to the prefixed namespace.
     *
     * @covers ::getOriginalNamespace
     */
    public function test_discovered_original_namespace_is_reused(): void
    {
        [$discoveredSymbols, $package] = $this->getAlreadyPrefixedPsrLog();

        $autoloadNamespace = new NamespaceSymbol('Psr\Log');
        $autoloadNamespace->setDoRename(true);
        $autoloadNamespace->setLocalReplacement('Prefix\Psr\Log');
        $discoveredSymbols->add($autoloadNamespace);

        $sut = new AlreadyPrefixedSymbols($this->getConfig(['psr/log' => $package]));

        $result = $sut->getOriginalSymbols($discoveredSymbols);

        self::assertNull($result->getNamespace('Psr\Log'), 'The discovered namespace is not added again.');

        $loggerInterface = $result->getInterface('Psr\Log\LoggerInterface');
        self::assertNotNull($loggerInterface);
        self::assertSame($autoloadNamespace, $loggerInterface->getNamespace());
        self::assertSame('Prefix\Psr\Log\LoggerInterface', $loggerInterface->getReplacementFqdnName());
    }

    /**
     * @covers ::getOriginalNamespace
     */
    public function test_original_namespace_renamed_to_something_else_is_left_alone(): void
    {
        [$discoveredSymbols, $package] = $this->getAlreadyPrefixedPsrLog();

        $autoloadNamespace = new NamespaceSymbol('Psr\Log');
        $autoloadNamespace->setDoRename(true);
        $autoloadNamespace->setLocalReplacement('Other\Psr\Log');
        $discoveredSymbols->add($autoloadNamespace);

        $sut = new AlreadyPrefixedSymbols($this->getConfig(['psr/log' => $package]), $this->getLogger());

        self::assertCount(0, $sut->getOriginalSymbols($discoveredSymbols));
    }

    /**
     * When the package was reinstalled, its original symbols are discovered as usual.
     *
     * @covers ::getOriginalSymbols
     */
    public function test_already_discovered_original_symbol_is_not_created(): void
    {
        [$discoveredSymbols, $package, $prefixedNamespace] = $this->getAlreadyPrefixedPsrLog();

        $originalNamespace = new NamespaceSymbol('Psr\Log', $this->getFile($package, 'src/LogLevel.php'));
        $originalNamespace->setDoRename(true);
        $originalNamespace->setLocalReplacement('Prefix\Psr\Log');
        $discoveredSymbols->add($originalNamespace);
        $discoveredSymbols->add(new InterfaceSymbol('Psr\Log\LoggerInterface', $this->getFile($package, 'src/Other.php'), $originalNamespace));

        $sut = new AlreadyPrefixedSymbols($this->getConfig(['psr/log' => $package]));

        $result = $sut->getOriginalSymbols($discoveredSymbols);

        self::assertNull($result->getInterface('Psr\Log\LoggerInterface'));
        self::assertNotNull($result->getClass('Psr\Log\NullLogger'));
    }

    /**
     * A package which is not being prefixed may legitimately use a namespace which begins with the prefix.
     *
     * @covers ::getOriginalSymbols
     */
    public function test_packages_not_being_prefixed_are_ignored(): void
    {
        [$discoveredSymbols] = $this->getAlreadyPrefixedPsrLog();

        $sut = new AlreadyPrefixedSymbols($this->getConfig(['other/package' => $this->getPackage('other/package')]));

        self::assertCount(0, $sut->getOriginalSymbols($discoveredSymbols));
    }

    /**
     * E.g. symbols in `exclude_from_prefix.namespaces`.
     *
     * @covers ::getOriginalSymbols
     */
    public function test_symbols_not_to_rename_are_ignored(): void
    {
        [$discoveredSymbols, $package] = $this->getAlreadyPrefixedPsrLog();

        foreach ($discoveredSymbols->toArray() as $symbol) {
            $symbol->setDoRename(false);
        }

        $sut = new AlreadyPrefixedSymbols($this->getConfig(['psr/log' => $package]));

        self::assertCount(0, $sut->getOriginalSymbols($discoveredSymbols));
    }

    /**
     * Global functions are prefixed by `functions_prefix`, not the namespace prefix; namespaced functions are mapped.
     *
     * @covers ::createOriginalSymbol
     */
    public function test_namespaced_function_is_mapped(): void
    {
        $package = $this->getPackage('acme/helpers');
        $file = $this->getFile($package, 'functions.php');

        $prefixedNamespace = new NamespaceSymbol('Prefix\Acme', $file);
        $prefixedNamespace->setDoRename(true);
        $function = new FunctionSymbol('Prefix\Acme\helper', $file, $prefixedNamespace);
        $function->setDoRename(true);

        $globalNamespace = new NamespaceSymbol('\\', $file);
        $globalFunction = new FunctionSymbol('Prefix_global_helper', $file, $globalNamespace);
        $globalFunction->setDoRename(true);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($prefixedNamespace);
        $discoveredSymbols->add($function);
        $discoveredSymbols->add($globalFunction);

        $sut = new AlreadyPrefixedSymbols($this->getConfig(['acme/helpers' => $package]));

        $result = $sut->getOriginalSymbols($discoveredSymbols);

        $originalFunction = $result->getFunction('Acme\helper');
        self::assertInstanceOf(FunctionSymbol::class, $originalFunction);
        self::assertSame('Prefix\Acme\helper', $originalFunction->getReplacementFqdnName());
        self::assertCount(2, $result, 'The namespace and the namespaced function only.');
    }

    /**
     * @return array<string, array{0:bool, 1:?string}>
     */
    public static function notApplicableProvider(): array
    {
        return [
            'target directory is not vendor' => [false, 'Prefix\\'],
            'no namespace prefix' => [true, null],
            'empty namespace prefix' => [true, '\\'],
        ];
    }

    /**
     * @dataProvider notApplicableProvider
     * @covers ::getOriginalSymbols
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('notApplicableProvider')]
    public function test_not_applicable(bool $isTargetDirectoryVendor, ?string $namespacePrefix): void
    {
        [$discoveredSymbols, $package] = $this->getAlreadyPrefixedPsrLog();

        $sut = new AlreadyPrefixedSymbols(
            $this->getConfig(['psr/log' => $package], $isTargetDirectoryVendor, $namespacePrefix)
        );

        self::assertCount(0, $sut->getOriginalSymbols($discoveredSymbols));
    }

    /**
     * @return array{0:DiscoveredSymbols, 1:ComposerPackage, 2:NamespaceSymbol}
     */
    protected function getAlreadyRenamedPsrLog(string $renamedNamespaceName, string $originalNamespaceName = 'Psr\Log'): array
    {
        $package = $this->getPackage('psr/log');
        $file = $this->getFile($package, 'src/LoggerInterface.php');

        // From the package's `autoload.psr-4` key, renamed by the replacement pattern.
        $autoloadNamespace = new NamespaceSymbol($originalNamespaceName);
        $autoloadNamespace->setDoRename(true);
        $autoloadNamespace->setLocalReplacement($renamedNamespaceName);

        // Declared in the files; {@see ChangeEnumerator::keepAlreadyRenamedNamespaces()} leaves it.
        $renamedNamespace = new NamespaceSymbol($renamedNamespaceName, $file);
        $renamedNamespace->setDoRename(true);

        $loggerInterface = new InterfaceSymbol($renamedNamespaceName . '\LoggerInterface', $file, $renamedNamespace);
        $loggerInterface->setDoRename(true);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($autoloadNamespace);
        $discoveredSymbols->add($renamedNamespace);
        $discoveredSymbols->add($loggerInterface);

        return [$discoveredSymbols, $package, $autoloadNamespace];
    }

    /**
     * `namespace_replacement_patterns` cannot be reversed; the original namespace is the one renamed to it.
     *
     * @covers ::getOriginalSymbols
     * @covers ::getOriginalNamespaceName
     */
    public function test_replacement_pattern_without_namespace_prefix(): void
    {
        [$discoveredSymbols, $package, $autoloadNamespace] = $this->getAlreadyRenamedPsrLog('Acme\Log');

        $sut = new AlreadyPrefixedSymbols($this->getConfig(['psr/log' => $package], true, null));

        $result = $sut->getOriginalSymbols($discoveredSymbols);

        $loggerInterface = $result->getInterface('Psr\Log\LoggerInterface');
        self::assertNotNull($loggerInterface);
        self::assertSame($autoloadNamespace, $loggerInterface->getNamespace());
        self::assertSame('Acme\Log\LoggerInterface', $loggerInterface->getReplacementFqdnName());
    }

    /**
     * E.g. `"~Psr\\\\(.*)~": "Acme\\Psr\\\\$1"`.
     *
     * @covers ::getOriginalNamespaceName
     */
    public function test_replacement_pattern_with_back_reference(): void
    {
        [$discoveredSymbols, $package] = $this->getAlreadyRenamedPsrLog('Acme\Psr\Log');

        $sut = new AlreadyPrefixedSymbols($this->getConfig(['psr/log' => $package], true, 'Default\Prefix'));

        $result = $sut->getOriginalSymbols($discoveredSymbols);

        $loggerInterface = $result->getInterface('Psr\Log\LoggerInterface');
        self::assertNotNull($loggerInterface);
        self::assertSame('Acme\Psr\Log\LoggerInterface', $loggerInterface->getReplacementFqdnName());
    }

    /**
     * A namespace within the renamed one, e.g. `Psr\Log\Test`, is not in the `autoload` key.
     *
     * @covers ::getOriginalNamespaceName
     * @covers ::getOriginalNamespace
     */
    public function test_sub_namespace(): void
    {
        [$discoveredSymbols, $package] = $this->getAlreadyRenamedPsrLog('Acme\Log');

        $file = $this->getFile($package, 'src/Test/TestLogger.php');
        $renamedSubNamespace = new NamespaceSymbol('Acme\Log\Test', $file);
        $renamedSubNamespace->setDoRename(true);
        $testLogger = new ClassSymbol('Acme\Log\Test\TestLogger', $file, $renamedSubNamespace);
        $testLogger->setDoRename(true);
        $discoveredSymbols->add($renamedSubNamespace);
        $discoveredSymbols->add($testLogger);

        $sut = new AlreadyPrefixedSymbols($this->getConfig(['psr/log' => $package], true, null));

        $result = $sut->getOriginalSymbols($discoveredSymbols);

        $originalSubNamespace = $result->getNamespace('Psr\Log\Test');
        self::assertNotNull($originalSubNamespace);
        self::assertSame('Acme\Log\Test', $originalSubNamespace->getReplacementFqdnName());

        $originalTestLogger = $result->getClass('Psr\Log\Test\TestLogger');
        self::assertNotNull($originalTestLogger);
        self::assertSame('Acme\Log\Test\TestLogger', $originalTestLogger->getReplacementFqdnName());
    }

    /**
     * On the first run, the files declare `Psr\Log`, which is being renamed: nothing to add.
     *
     * @covers ::getOriginalSymbols
     */
    public function test_namespace_being_renamed_now_is_ignored(): void
    {
        $package = $this->getPackage('psr/log');
        $file = $this->getFile($package, 'src/LoggerInterface.php');

        $namespace = new NamespaceSymbol('Psr\Log', $file);
        $namespace->setDoRename(true);
        $namespace->setLocalReplacement('Acme\Log');

        $loggerInterface = new InterfaceSymbol('Psr\Log\LoggerInterface', $file, $namespace);
        $loggerInterface->setDoRename(true);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespace);
        $discoveredSymbols->add($loggerInterface);

        $sut = new AlreadyPrefixedSymbols($this->getConfig(['psr/log' => $package], true, 'Acme'));

        self::assertCount(0, $sut->getOriginalSymbols($discoveredSymbols));
    }
}
