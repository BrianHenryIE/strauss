<?php

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\TestCase;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Types\DiscoveredSymbols
 */
class DiscoveredSymbolsTest extends TestCase
{

    /**
     * @covers ::add
     * @covers ::getSymbols
     */
    public function testReturnsFunctions(): void
    {
        $sut = new DiscoveredSymbols();

        $file = new File(
            'vendor/path/to/file.php',
            'path/to/file.php',
            'vendor-prefixed/path/to/file.php',
        );

        $symbol = new FunctionSymbol('myFunction', $file, new NamespaceSymbol('\\'));

        $sut->add($symbol);

        $this->assertNotEmpty($sut->getSymbols());
    }

    /**
     * @covers ::getNamespace
     */
    public function testGetNamespaceSymbol(): void
    {
        $sut = new DiscoveredSymbols();

        $file = new File(
            'vendor/path/to/file.php',
            'path/to/file.php',
            'vendor-prefixed/path/to/file.php',
        );

        $symbol = new NamespaceSymbol('myNamespace', $file);

        $sut->add($symbol);

        $result = $sut->getNamespace('myNamespace');

        $this->assertEquals($symbol, $result);
    }

    /**
     * @covers ::getNamespace
     */
    public function testGetNamespaceSymbolMissing(): void
    {
        $sut = new DiscoveredSymbols();

        $result = $sut->getNamespace('myNamespace');

        $this->assertNull($result);
    }

    /**
     * @covers ::toArray
     */
    public function testToArray(): void
    {
        $sut = new DiscoveredSymbols();

        $file = new File(
            'vendor/path/to/file.php',
            'path/to/file.php',
            'vendor-prefixed/path/to/file.php',
        );

        $sut->add(new NamespaceSymbol('myNamespace'));
        $sut->add(new ClassSymbol('myClass', $file, new NamespaceSymbol('\\'), false));

        // The two added plus global namespace.
        $this->assertCount(2, $sut->toArray());
    }

    /**
     * The `add()`/`has()` switches use exact `get_class()` matching, so without an explicit EnumSymbol case
     * they would throw InvalidArgumentException.
     *
     * @covers ::add
     * @covers ::has
     * @covers ::getEnum
     * @covers ::getDiscoveredEnums
     */
    public function testAddEnumSymbol(): void
    {
        $sut = new DiscoveredSymbols();

        $file = new File(
            'vendor/path/to/file.php',
            'path/to/file.php',
            'vendor-prefixed/path/to/file.php',
        );

        $symbol = new EnumSymbol('Foo\Bar\Status', $file, new NamespaceSymbol('Foo\Bar'), 'string');

        $sut->add($symbol);

        $this->assertTrue($sut->has($symbol));
        $this->assertSame($symbol, $sut->getEnum('Foo\Bar\Status'));
        $this->assertCount(1, $sut->getDiscoveredEnums());
        $this->assertSame($symbol, $sut->getDiscoveredEnums()->toArray()['Foo\Bar\Status']);
    }

    /**
     * @covers ::getEnum
     */
    public function testGetEnumMissing(): void
    {
        $sut = new DiscoveredSymbols();

        $this->assertNull($sut->getEnum('Foo\Bar\Status'));
    }

    /**
     * @covers ::get
     * @covers ::getNamespacedSymbols
     */
    public function testEnumIncludedInGetAndNamespacedSymbols(): void
    {
        $sut = new DiscoveredSymbols();

        $file = new File(
            'vendor/path/to/file.php',
            'path/to/file.php',
            'vendor-prefixed/path/to/file.php',
        );

        $symbol = new EnumSymbol('Foo\Bar\Status', $file, new NamespaceSymbol('Foo\Bar'));

        $sut->add($symbol);

        $this->assertSame($symbol, $sut->get('Foo\Bar\Status'));
        $this->assertCount(1, $sut->getNamespacedSymbols()->toArray());
        $this->assertCount(1, $sut->toArray());
    }

    /**
     * @covers ::getNamespacedSymbols
     */
    public function testGetClassesInterfacesTraits(): void
    {
        $sut = new DiscoveredSymbols();

        $file = new File(
            'vendor/path/to/file.php',
            'path/to/file.php',
            'vendor-prefixed/path/to/file.php',
        );

        $sut->add(new NamespaceSymbol('myNamespace'));
        $sut->add(new ClassSymbol('myClass', $file, new NamespaceSymbol('\\'), false));

        $result = $sut->getNamespacedSymbols()->toArray();

        $this->assertCount(1, $result);
        /** @var NamespacedSymbol $firstResult */
        $firstResult = array_pop($result);
        $this->assertEquals('myClass', $firstResult->getOriginalFqdnName());
    }
}
