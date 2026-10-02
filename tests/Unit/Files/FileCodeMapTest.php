<?php

namespace BrianHenryIE\Strauss\Files;

use BrianHenryIE\Strauss\Pipeline\FileSymbol\SymbolDefinition;
use BrianHenryIE\Strauss\TestCase;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Files\FileCodeMap
 */
class FileCodeMapTest extends TestCase
{
    /**
     * @covers ::matchesContent
     */
    public function testMatchesContent(): void
    {
        $contents = '<?php echo "hello";';
        $map = new FileCodeMap([], $contents);

        self::assertTrue($map->matchesContent($contents));
        self::assertFalse($map->matchesContent($contents . ' '));
    }

    /**
     * @covers ::getLocationsOfType
     */
    public function testGetLocationsOfType(): void
    {
        $name = new CodeLocation(CodeLocation::NAME, 0, 'Foo');
        $comment = new CodeLocation(CodeLocation::COMMENT, 4, '// c');
        $map = new FileCodeMap([$name, $comment], 'Foo // c');

        self::assertSame([$name], $map->getLocationsOfType(CodeLocation::NAME));
        self::assertSame([$comment], $map->getLocationsOfType(CodeLocation::COMMENT));
        self::assertCount(2, $map->getLocationsOfType(CodeLocation::NAME, CodeLocation::COMMENT));
    }

    /**
     * @covers ::getDefinitions
     * @covers ::getDefinitionsByType
     * @covers ::getUsages
     * @covers ::getUsagesByType
     * @covers ::getUsedNames
     * @covers ::getRegions
     */
    public function testDefinitionsAndUsagesByType(): void
    {
        $classDefinition = new SymbolDefinition(SymbolDefinition::TYPE_CLASS, 'Foo', '\\');
        $functionDefinition = new SymbolDefinition(SymbolDefinition::TYPE_FUNCTION, 'foo', '\\');

        $name = new CodeLocation(CodeLocation::NAME, 0, 'Bar');
        $call = new CodeLocation(CodeLocation::FUNCTION_CALL, 4, 'baz');
        $comment = new CodeLocation(CodeLocation::COMMENT, 10, '// c');

        $map = new FileCodeMap([$name, $call, $comment], 'Bar baz() // c', [$classDefinition, $functionDefinition]);

        self::assertSame([$classDefinition, $functionDefinition], $map->getDefinitions());
        self::assertSame([$classDefinition], $map->getDefinitions(SymbolDefinition::TYPE_CLASS));
        self::assertSame([$functionDefinition], $map->getDefinitionsByType()[SymbolDefinition::TYPE_FUNCTION]);
        self::assertSame([], $map->getDefinitionsByType()[SymbolDefinition::TYPE_TRAIT]);

        self::assertSame([$name, $call], $map->getUsages());
        self::assertSame([$call], $map->getUsages(CodeLocation::FUNCTION_CALL));
        self::assertSame([$name], $map->getUsagesByType()[CodeLocation::NAME]);
        self::assertSame(['Bar', 'baz'], $map->getUsedNames());

        self::assertSame([$comment], $map->getRegions());
    }
}
