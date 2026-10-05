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

    /**
     * A file with locations of several types, given out of order.
     */
    protected function getMixedMap(): FileCodeMap
    {
        $contents = '<?php /* c */ use A\B; new B(); foo(); // d' . "\n" . '$s = "str"; C::x(); bar();';

        $locations = [];
        foreach ([
            [CodeLocation::FUNCTION_CALL, 'bar'],
            [CodeLocation::COMMENT, '/* c */'],
            [CodeLocation::NAME, 'C'],
            [CodeLocation::USE_NAME, 'A\B'],
            [CodeLocation::STRING, '"str"'],
            [CodeLocation::NAME, 'B('],
            [CodeLocation::FUNCTION_CALL, 'foo'],
            [CodeLocation::COMMENT, '// d'],
        ] as [$type, $text]) {
            $locations[] = new CodeLocation($type, (int) strpos($contents, $text), rtrim($text, '('));
        }

        return new FileCodeMap($locations, $contents);
    }

    /**
     * @param CodeLocation[] $locations
     *
     * @return string[]
     */
    protected function texts(array $locations): array
    {
        return array_map(fn(CodeLocation $location): string => $location->getText(), $locations);
    }

    /**
     * Whichever types are asked for, in whatever order, the locations come back in source order: the same as
     * filtering every location.
     *
     * @covers ::__construct
     * @covers ::getLocationsOfType
     * @covers ::groupLocationsByType
     */
    public function testGetLocationsOfSeveralTypesAreInSourceOrder(): void
    {
        $map = $this->getMixedMap();

        self::assertSame(
            ['/* c */', 'A\B', 'B', 'foo', '// d', '"str"', 'C', 'bar'],
            $this->texts($map->getLocations())
        );

        foreach ([
            [CodeLocation::NAME],
            [CodeLocation::FUNCTION_CALL, CodeLocation::NAME],
            [CodeLocation::NAME, CodeLocation::FUNCTION_CALL],
            [CodeLocation::COMMENT, CodeLocation::STRING, CodeLocation::USE_NAME],
            [CodeLocation::NAME, CodeLocation::NAME],
            [CodeLocation::NAME, CodeLocation::DOC_COMMENT],
            CodeLocation::REGION_TYPES,
            CodeLocation::NAME_TYPES,
            CodeLocation::USAGE_TYPES,
        ] as $types) {
            $expected = array_values(array_filter(
                $map->getLocations(),
                fn(CodeLocation $location): bool => in_array($location->getType(), $types, true)
            ));

            self::assertSame($expected, $map->getLocationsOfType(...$types), implode(', ', $types));
        }

        self::assertSame(['B', 'foo', 'C', 'bar'], $this->texts($map->getLocationsOfType(CodeLocation::FUNCTION_CALL, CodeLocation::NAME)));
        self::assertSame(['/* c */', '// d', '"str"'], $this->texts($map->getRegions()));
    }

    /**
     * @covers ::getLocationsOfType
     */
    public function testGetLocationsOfATypeTheFileHasNoneOf(): void
    {
        $map = $this->getMixedMap();

        self::assertSame([], $map->getLocationsOfType(CodeLocation::DOC_COMMENT));
        self::assertSame([], $map->getLocationsOfType(CodeLocation::DOC_COMMENT, CodeLocation::INLINE_HTML));
        self::assertSame([], $map->getLocationsOfType());
        self::assertSame([], (new FileCodeMap([], ''))->getLocationsOfType(CodeLocation::NAME));
    }

    /**
     * Maps are serialized to cross the process boundary from the workers. The grouping by type is derived, so it
     * is not sent, and is rebuilt on arrival.
     *
     * @covers ::__sleep
     * @covers ::__wakeup
     */
    public function testSerializationRoundTrip(): void
    {
        $map = $this->getMixedMap();

        $serialized = serialize($map);
        /** @var FileCodeMap $unserialized */
        $unserialized = unserialize($serialized);

        self::assertStringNotContainsString('locationsByType', $serialized);
        self::assertEquals($map, $unserialized);
        self::assertSame(
            $this->texts($map->getLocationsOfType(CodeLocation::NAME, CodeLocation::FUNCTION_CALL)),
            $this->texts($unserialized->getLocationsOfType(CodeLocation::NAME, CodeLocation::FUNCTION_CALL))
        );
        self::assertSame(['/* c */', '// d'], $this->texts($unserialized->getLocationsOfType(CodeLocation::COMMENT)));
        // The grouped locations are the same objects as in the full list.
        self::assertSame($unserialized->getLocations()[0], $unserialized->getLocationsOfType(CodeLocation::COMMENT)[0]);
    }
}
