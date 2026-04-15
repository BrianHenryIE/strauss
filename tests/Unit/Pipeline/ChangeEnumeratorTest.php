<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Config\ChangeEnumeratorConfigInterface;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Pipeline\ChangeEnumerator;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
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
        $config = Mockery::mock(\BrianHenryIE\Strauss\Config\ChangeEnumeratorConfigInterface::class);
        $config->expects('getClassmapPrefix')->andReturn('Class_Prefix_');
        $config->expects('getFunctionsPrefix')->andReturn('functions_prefix_')->atLeast()->once();

        $sut = new ChangeEnumerator($config, $this->getTestLogger());

        $discoveredSymbols = new DiscoveredSymbols();
        $symbol = new FunctionSymbol('myFunction', new File('/path/to/file.php', 'file.php'));
        $discoveredSymbols->add($symbol);

        $sut->determineReplacements($discoveredSymbols);

        $this->assertEquals(
            'functions_prefix_myFunction',
            $symbol->getReplacement()
        );
    }
}
