<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Pipeline\ChangeEnumerator;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Mockery;

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
        $config = Mockery::mock(\BrianHenryIE\Strauss\Config\ChangeEnumeratorConfigInterface::class);
        $config->expects('getClassmapPrefix')->never();
        $config->expects('getNamespaceReplacementPatterns')->andReturn([]);
        $config->expects('getNamespacePrefix')->andReturn('Prefix')->atLeast()->once();
        $config->expects('getFunctionsPrefix')->andReturn('functions_prefix_')->atLeast()->once();

        $sut = new ChangeEnumerator($config, $this->getInMemoryFileSystem());

        $discoveredSymbols = new DiscoveredSymbols();
        $symbol = new FunctionSymbol('myFunction', new File('/path/to/file.php'));
        $discoveredSymbols->add($symbol);

        $sut->determineReplacements($discoveredSymbols);

        $this->assertEquals(
            'functions_prefix_myFunction',
            $symbol->getReplacement()
        );
    }
}
