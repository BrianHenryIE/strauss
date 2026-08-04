<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Config\ChangeEnumeratorConfigInterface;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\EnumSymbol;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
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
        $config = Mockery::mock(ChangeEnumeratorConfigInterface::class);
        $config->expects('getClassmapPrefix')->andReturn('Class_Prefix_');
        $config->expects('getFunctionsPrefix')->andReturn('functions_prefix_')->atLeast()->once();
        $config->allows('getConstantsPrefix')->andReturnNull();

        $sut = new ChangeEnumerator($config, $this->getTestLogger());

        $discoveredSymbols = new DiscoveredSymbols();
        $symbol = new FunctionSymbol(
            'myFunction',
            new File(
                '/path/to/file.php',
                'file.php',
                '/destination-path/to/file.php'
            ),
            new NamespaceSymbol('\\')
        );
        $discoveredSymbols->add($symbol);

        $sut->determineReplacements($discoveredSymbols);

        $this->assertEquals(
            'functions_prefix_myFunction',
            $symbol->getLocalReplacement()
        );
    }

    /**
     * A namespaced enum gets its replacement FQDN from the namespace replacement, same as classes.
     *
     * @covers ::determineReplacements
     */
    public function testNamespacedEnumReplacement(): void
    {
        /** @var MockInterface&ChangeEnumeratorConfigInterface $config */
        $config = Mockery::mock(ChangeEnumeratorConfigInterface::class);
        $config->allows('getNamespacePrefix')->andReturn('Prefix');
        $config->allows('getNamespaceReplacementPatterns')->andReturn([]);
        $config->allows('getExcludeNamespacesFromPrefixing')->andReturn([]);
        $config->allows('getClassmapPrefix')->andReturn('Class_Prefix_');
        $config->allows('getFunctionsPrefix')->andReturnNull();
        $config->allows('getConstantsPrefix')->andReturnNull();

        $sut = new ChangeEnumerator($config, $this->getTestLogger());

        $file = new File(
            '/path/to/file.php',
            'file.php',
            '/destination-path/to/file.php'
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $namespaceSymbol = new NamespaceSymbol('My\NS', $file);
        $discoveredSymbols->add($namespaceSymbol);

        $enumSymbol = new EnumSymbol('My\NS\Status', $file, $namespaceSymbol, 'string');
        $discoveredSymbols->add($enumSymbol);

        $sut->determineReplacements($discoveredSymbols);

        $this->assertEquals(
            'Prefix\My\NS\Status',
            $enumSymbol->getReplacementFqdnName()
        );
    }

    /**
     * A global enum gets the classmap prefix, same as global classes.
     *
     * @covers ::determineReplacements
     */
    public function testGlobalEnumReplacement(): void
    {
        /** @var MockInterface&ChangeEnumeratorConfigInterface $config */
        $config = Mockery::mock(ChangeEnumeratorConfigInterface::class);
        $config->allows('getNamespacePrefix')->andReturn('Prefix');
        $config->allows('getNamespaceReplacementPatterns')->andReturn([]);
        $config->allows('getExcludeNamespacesFromPrefixing')->andReturn([]);
        $config->allows('getClassmapPrefix')->andReturn('Class_Prefix_');
        $config->allows('getFunctionsPrefix')->andReturnNull();
        $config->allows('getConstantsPrefix')->andReturnNull();

        $sut = new ChangeEnumerator($config, $this->getTestLogger());

        $file = new File(
            '/path/to/file.php',
            'file.php',
            '/destination-path/to/file.php'
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $enumSymbol = new EnumSymbol('GlobalSuit', $file, new NamespaceSymbol('\\'));
        $discoveredSymbols->add($enumSymbol);

        $sut->determineReplacements($discoveredSymbols);

        $this->assertEquals(
            'Class_Prefix_GlobalSuit',
            $enumSymbol->getReplacementFqdnName()
        );
    }

    /**
     * An already-prefixed global enum is not prefixed twice.
     *
     * @covers ::determineReplacements
     */
    public function testGlobalEnumAlreadyPrefixedIsSkipped(): void
    {
        /** @var MockInterface&ChangeEnumeratorConfigInterface $config */
        $config = Mockery::mock(ChangeEnumeratorConfigInterface::class);
        $config->allows('getNamespacePrefix')->andReturn('Prefix');
        $config->allows('getNamespaceReplacementPatterns')->andReturn([]);
        $config->allows('getExcludeNamespacesFromPrefixing')->andReturn([]);
        $config->allows('getClassmapPrefix')->andReturn('Class_Prefix_');
        $config->allows('getFunctionsPrefix')->andReturnNull();
        $config->allows('getConstantsPrefix')->andReturnNull();

        $sut = new ChangeEnumerator($config, $this->getTestLogger());

        $file = new File(
            '/path/to/file.php',
            'file.php',
            '/destination-path/to/file.php'
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $enumSymbol = new EnumSymbol('Class_Prefix_GlobalSuit', $file, new NamespaceSymbol('\\'));
        $discoveredSymbols->add($enumSymbol);

        $sut->determineReplacements($discoveredSymbols);

        $this->assertEquals(
            'Class_Prefix_GlobalSuit',
            $enumSymbol->getReplacementFqdnName()
        );
    }
}
