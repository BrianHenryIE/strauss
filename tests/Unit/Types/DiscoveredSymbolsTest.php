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

        $symbol = new FunctionSymbol('myFunction', Mockery::mock(File::class)->makePartial());

        $sut->add($symbol);

        $this->assertNotEmpty($sut->getSymbols());
    }
}
