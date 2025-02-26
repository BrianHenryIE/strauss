<?php

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\TestCase;
use Mockery;

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

        $file = Mockery::mock(File::class)->makePartial();
        $file->expects('getSourcePath')->once()->andReturn('/path/to/file.php');

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

        $file = Mockery::mock(File::class)->makePartial();
        $file->expects('getSourcePath')->once()->andReturn('/path/to/file.php');

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
}
