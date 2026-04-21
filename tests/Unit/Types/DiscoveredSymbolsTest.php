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

        $symbol = new FunctionSymbol('myFunction', $file);

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
        $sut->add(new ClassSymbol('myClass', $file));

        // The two added plus global namespace.
        $this->assertCount(2, $sut->toArray());
    }

    /**
     * @covers ::getClassesInterfacesTraits
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
        $sut->add(new ClassSymbol('myClass', $file));

        $result = $sut->getClassesInterfacesTraits()->toArray();

        $this->assertCount(1, $result);
        $firstResult = array_pop($result);
        $this->assertEquals('myClass', $firstResult->getOriginalSymbol());
    }
}
