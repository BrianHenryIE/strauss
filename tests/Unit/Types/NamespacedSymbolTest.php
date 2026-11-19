<?php

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\TestCase;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Types\NamespacedSymbol
 */
class NamespacedSymbolTest extends TestCase
{
    protected function getFile(): File
    {
        return new File('/vendor/psr/log/src/LoggerInterface.php', 'psr/log/src/LoggerInterface.php', '/vendor/psr/log/src/LoggerInterface.php');
    }

    /**
     * Its name before renaming is its namespace's, so only namespaces need it set.
     *
     * @covers ::getInverseReplacement
     */
    public function test_inverse_replacement_is_in_the_namespace_inverse_replacement(): void
    {
        $namespace = new NamespaceSymbol('Prefix\Psr\Log', $this->getFile());
        $sut = new InterfaceSymbol('Prefix\Psr\Log\LoggerInterface', $this->getFile(), $namespace);

        self::assertNull($sut->getInverseReplacement());

        $namespace->setInverseReplacement('Psr\Log');

        self::assertSame('Psr\Log\LoggerInterface', $sut->getInverseReplacement());
    }

    /**
     * A global symbol has no namespace to take it from, so its own is used.
     *
     * @covers ::getInverseReplacement
     */
    public function test_global_symbol_inverse_replacement(): void
    {
        $sut = new ClassSymbol('Prefix_Logger', $this->getFile(), new NamespaceSymbol('\\'));

        self::assertNull($sut->getInverseReplacement());

        $sut->setInverseReplacement('Logger');

        self::assertSame('Logger', $sut->getInverseReplacement());
    }

    /**
     * @covers ::withOriginalName
     */
    public function test_with_original_name(): void
    {
        $file = $this->getFile();

        $sut = new FunctionSymbol('prefix_helper', $file, new NamespaceSymbol('\\'));
        $sut->setDoRename(true);
        $sut->setInverseReplacement('helper');

        $copy = $sut->withOriginalName('helper');

        self::assertSame('helper', $copy->getOriginalFqdnName());
        self::assertSame('helper', $copy->getOriginalLocalName());
        self::assertSame('prefix_helper', $copy->getReplacementFqdnName());
        self::assertTrue($copy->isDoRename());
        self::assertNull($copy->getInverseReplacement());
        self::assertSame($sut->getSourceFiles(), $copy->getSourceFiles());

        // The original is unchanged and the copy is not added to the file's symbols.
        self::assertSame('prefix_helper', $sut->getOriginalFqdnName());
        self::assertSame('prefix_helper', $sut->getReplacementFqdnName());
        self::assertSame('helper', $sut->getInverseReplacement());
        self::assertSame([$sut], array_values($file->getDiscoveredSymbols()->toArray()));
    }

    /**
     * @covers ::withNamespace
     */
    public function test_with_namespace(): void
    {
        $file = $this->getFile();

        $namespace = new NamespaceSymbol('Prefix\Psr\Log', $file);
        $namespace->setDoRename(true);
        $namespace->setInverseReplacement('Psr\Log');

        $sut = new ClassSymbol('Prefix\Psr\Log\NullLogger', $file, $namespace, true);
        $sut->setDoRename(true);

        $inverseReplacementNamespace = new NamespaceSymbol('Psr\Log');
        $inverseReplacementNamespace->setDoRename(true);
        $inverseReplacementNamespace->setLocalReplacement('Prefix\Psr\Log');

        $copy = $sut->withNamespace($inverseReplacementNamespace);

        self::assertSame(ClassSymbol::class, get_class($copy));
        self::assertTrue($copy->isAbstract());
        self::assertSame($inverseReplacementNamespace, $copy->getNamespace());
        self::assertSame('Psr\Log\NullLogger', $copy->getOriginalFqdnName());
        self::assertSame('Prefix\Psr\Log\NullLogger', $copy->getReplacementFqdnName());
        self::assertNull($copy->getInverseReplacement());

        // The original is unchanged, and the copy is not one of the file's symbols.
        self::assertSame($namespace, $sut->getNamespace());
        self::assertSame('Prefix\Psr\Log\NullLogger', $sut->getOriginalFqdnName());
        self::assertSame([$sut], array_values($file->getDiscoveredSymbols()->getAllClasses()->toArray()));
    }
}
