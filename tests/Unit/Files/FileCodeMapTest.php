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
     * @covers ::applyEdits
     */
    public function testApplyEditsShiftsLaterLocations(): void
    {
        $contents = '<?php new Foo(); new Bar();';
        //                     10^      21^
        $fooLocation = new CodeLocation(CodeLocation::NAME, 10, 'Foo');
        $barLocation = new CodeLocation(CodeLocation::NAME, 21, 'Bar');
        $map = new FileCodeMap([$fooLocation, $barLocation], $contents);

        $result = $map->applyEdits($contents, [
            ['start' => 10, 'end' => 13, 'replacement' => 'Prefixed_Foo'],
        ]);

        self::assertSame('<?php new Prefixed_Foo(); new Bar();', $result);

        self::assertSame('Prefixed_Foo', $fooLocation->getText());
        self::assertSame(10, $fooLocation->getStart());

        self::assertSame('Bar', $barLocation->getText());
        self::assertSame(30, $barLocation->getStart());
        self::assertSame('Bar', substr($result, $barLocation->getStart(), $barLocation->getLength()));

        self::assertTrue($map->matchesContent($result));
    }

    /**
     * An edit inside a larger location — e.g. a classname inside a comment — is spliced into its text.
     *
     * @covers ::applyEdits
     */
    public function testApplyEditsSplicesEditInsideLocation(): void
    {
        $contents = '<?php /** @param Foo $foo */ $x = 1;';
        //                6^          17^
        $commentLocation = new CodeLocation(CodeLocation::DOC_COMMENT, 6, '/** @param Foo $foo */');
        $map = new FileCodeMap([$commentLocation], $contents);

        $result = $map->applyEdits($contents, [
            ['start' => 17, 'end' => 20, 'replacement' => 'Prefixed_Foo'],
        ]);

        self::assertSame('<?php /** @param Prefixed_Foo $foo */ $x = 1;', $result);
        self::assertSame('/** @param Prefixed_Foo $foo */', $commentLocation->getText());
        self::assertSame(
            $commentLocation->getText(),
            substr($result, $commentLocation->getStart(), $commentLocation->getLength())
        );
    }

    /**
     * A location partially overlapped by an edit can no longer be tracked and is dropped.
     *
     * @covers ::applyEdits
     */
    public function testApplyEditsDropsPartiallyOverlappedLocation(): void
    {
        $contents = 'aaabbbccc';
        $location = new CodeLocation(CodeLocation::NAME, 3, 'bbb');
        $map = new FileCodeMap([$location], $contents);

        $result = $map->applyEdits($contents, [
            ['start' => 1, 'end' => 5, 'replacement' => 'X'],
        ]);

        self::assertSame('aXbccc', $result);
        self::assertSame([], $map->getLocations());
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
     * @covers ::setPlannedEdits
     * @covers ::isPlanned
     * @covers ::applyPlannedEdits
     */
    public function testPlannedEditsAreConsumedWhenApplied(): void
    {
        $contents = '<?php new Foo();';
        $map = new FileCodeMap([new CodeLocation(CodeLocation::NAME, 10, 'Foo')], $contents);

        self::assertFalse($map->isPlanned());

        $map->setPlannedEdits([['start' => 10, 'end' => 13, 'replacement' => 'Bar']]);

        self::assertTrue($map->isPlanned());
        self::assertTrue($map->hasPlannedEdits());

        $result = $map->applyPlannedEdits($contents);

        self::assertSame('<?php new Bar();', $result);
        self::assertFalse($map->isPlanned());
        self::assertSame([], $map->getPlannedEdits());
        self::assertTrue($map->matchesContent($result));
    }
}
