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
     * @covers ::getUnprefixedFqdnName
     */
    public function test_unprefixed_name_is_in_the_namespace_unprefixed_name(): void
    {
        $namespace = new NamespaceSymbol('Prefix\Psr\Log', $this->getFile());
        $sut = new InterfaceSymbol('Prefix\Psr\Log\LoggerInterface', $this->getFile(), $namespace);

        self::assertNull($sut->getUnprefixedFqdnName());

        $namespace->setUnprefixedFqdnName('Psr\Log');

        self::assertSame('Psr\Log\LoggerInterface', $sut->getUnprefixedFqdnName());
    }

    /**
     * @covers ::getUnprefixedFqdnName
     */
    public function test_global_symbol_has_no_unprefixed_name(): void
    {
        $sut = new ClassSymbol('Prefix_Logger', $this->getFile(), new NamespaceSymbol('\\'));
        $sut->setUnprefixedFqdnName('Logger');

        self::assertNull($sut->getUnprefixedFqdnName());
    }

    /**
     * @covers ::withNamespace
     */
    public function test_with_namespace(): void
    {
        $file = $this->getFile();

        $namespace = new NamespaceSymbol('Prefix\Psr\Log', $file);
        $namespace->setDoRename(true);
        $namespace->setUnprefixedFqdnName('Psr\Log');

        $sut = new ClassSymbol('Prefix\Psr\Log\NullLogger', $file, $namespace, true);
        $sut->setDoRename(true);

        $unprefixedNamespace = new NamespaceSymbol('Psr\Log');
        $unprefixedNamespace->setDoRename(true);
        $unprefixedNamespace->setLocalReplacement('Prefix\Psr\Log');

        $copy = $sut->withNamespace($unprefixedNamespace);

        self::assertSame(ClassSymbol::class, get_class($copy));
        self::assertTrue($copy->isAbstract());
        self::assertSame($unprefixedNamespace, $copy->getNamespace());
        self::assertSame('Psr\Log\NullLogger', $copy->getOriginalFqdnName());
        self::assertSame('Prefix\Psr\Log\NullLogger', $copy->getReplacementFqdnName());
        self::assertNull($copy->getUnprefixedFqdnName());

        // The original is unchanged, and the copy is not one of the file's symbols.
        self::assertSame($namespace, $sut->getNamespace());
        self::assertSame('Prefix\Psr\Log\NullLogger', $sut->getOriginalFqdnName());
        self::assertSame([$sut], array_values($file->getDiscoveredSymbols()->getAllClasses()->toArray()));
    }
}
