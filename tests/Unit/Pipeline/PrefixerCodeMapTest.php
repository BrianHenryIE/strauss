<?php
/**
 * After a file has its symbols prefixed, the File object's code map — its parsed data — is updated to reflect the
 * changes, rather than discarded, so the file never needs to be parsed again.
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Config\PrefixerConfigInterface;
use BrianHenryIE\Strauss\Files\CodeLocation;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileCodeMap;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\PhpFileAnalyzer;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\Prefixer
 */
class PrefixerCodeMapTest extends TestCase
{
    /**
     * @covers ::replaceInString
     */
    public function testCodeMapIsStoredOnFileAndUpdatedAfterReplacements(): void
    {
        $contents = <<<'EOD'
<?php

namespace Vendor\Package;

/**
 * @param Vendor\Package\MyClass $thing
 */
class MyClass {
    public function id(): string {
        return 'Vendor\Package\MyClass';
    }
}
EOD;

        $file = new File('/a/path.php', 'path.php', '/a/path.php');

        $namespace = new NamespaceSymbol('Vendor\Package', $file);
        $namespace->setDoRename(true);
        $namespace->setLocalReplacement('Prefix\Vendor\Package');
        $classSymbol = new ClassSymbol('Vendor\Package\MyClass', $file, $namespace);
        $classSymbol->setDoRename(true);
        $discoveredSymbols = new DiscoveredSymbols([$namespace, $classSymbol]);

        $config = $this->createMock(PrefixerConfigInterface::class);
        $prefixer = new Prefixer($config, $this->getInMemoryFileSystem(), $this->getLogger());

        $result = $prefixer->replaceInString($discoveredSymbols, $contents, $file);

        self::assertStringContainsString('namespace Prefix\Vendor\Package;', $result);
        self::assertStringContainsString("'Prefix\\Vendor\\Package\\MyClass'", $result);

        $codeMap = $file->getCodeMap();
        self::assertNotNull($codeMap);

        // The map now describes the replaced contents, not the original.
        self::assertTrue($codeMap->matchesContent($result));
        self::assertFalse($codeMap->matchesContent($contents));

        // Every tracked location still points at the exact text at its position.
        foreach ($codeMap->getLocations() as $location) {
            self::assertSame(
                $location->getText(),
                substr($result, $location->getStart(), $location->getLength()),
                "Location of type {$location->getType()} is out of sync after replacement."
            );
        }

        $namespaceDefinitions = $codeMap->getLocationsOfType(CodeLocation::NAMESPACE_DEFINITION);
        self::assertCount(1, $namespaceDefinitions);
        self::assertSame('Prefix\Vendor\Package', $namespaceDefinitions[0]->getText());
    }

    /**
     * When the pipeline has already scanned and planned the file, the Prefixer only applies the plan: it parses
     * nothing and plans nothing.
     *
     * @covers ::replaceInString
     */
    public function testPlannedEditsAreAppliedWithoutParsing(): void
    {
        $contents = <<<'EOD'
<?php
namespace Vendor\Package;
class MyClass {}
EOD;

        $file = new File('/a/path.php', 'path.php', '/a/path.php');

        $namespace = new NamespaceSymbol('Vendor\Package', $file);
        $namespace->setDoRename(true);
        $namespace->setLocalReplacement('Prefix\Vendor\Package');
        $discoveredSymbols = new DiscoveredSymbols([$namespace]);

        $codeMap = (new PhpFileAnalyzer())->analyze($contents);
        $file->setCodeMap($codeMap);
        (new ChangePlanner())->planInFiles($discoveredSymbols, [$file]);

        $config = $this->createMock(PrefixerConfigInterface::class);
        $prefixer = new Prefixer($config, $this->getInMemoryFileSystem(), $this->getLogger());

        // An empty symbol set: the stored plan, not the symbols passed, determines the result.
        $result = $prefixer->replaceInString(new DiscoveredSymbols(), $contents, $file);

        self::assertStringContainsString('namespace Prefix\Vendor\Package;', $result);
        self::assertNull($file->getPlannedEdits(), 'The plan should be consumed.');
        self::assertTrue($file->getCodeMap()->matchesContent($result));
        // The original map is a value object: it still describes the original contents.
        self::assertTrue($codeMap->matchesContent($contents));
        self::assertFalse($this->getTestLogger()->hasInfoThatContains('Parsing'));
        self::assertFalse($this->getTestLogger()->hasDebugThatContains('Planning changes'));
    }

    /**
     * A second run over already-prefixed contents reuses the stored (updated) map rather than parsing again.
     *
     * @covers ::replaceInString
     */
    public function testSecondRunReusesUpdatedCodeMap(): void
    {
        $contents = <<<'EOD'
<?php
namespace Vendor\Package;
class MyClass {}
EOD;

        $file = new File('/a/path.php', 'path.php', '/a/path.php');

        $namespace = new NamespaceSymbol('Vendor\Package', $file);
        $namespace->setDoRename(true);
        $namespace->setLocalReplacement('Prefix\Vendor\Package');
        $discoveredSymbols = new DiscoveredSymbols([$namespace]);

        $config = $this->createMock(PrefixerConfigInterface::class);
        $prefixer = new Prefixer($config, $this->getInMemoryFileSystem(), $this->getLogger());

        $onceReplaced = $prefixer->replaceInString($discoveredSymbols, $contents, $file);
        $mapAfterFirstRun = $file->getCodeMap();

        $twiceReplaced = $prefixer->replaceInString($discoveredSymbols, $onceReplaced, $file);

        self::assertSame($onceReplaced, $twiceReplaced);
        self::assertSame($mapAfterFirstRun, $file->getCodeMap());

        $parseCount = count(array_filter(
            $this->getTestLogger()->records,
            fn(array $record): bool => str_contains($record['message'], 'Parsing')
        ));
        self::assertSame(1, $parseCount, 'The second run should reuse the stored code map instead of parsing.');
    }

    /**
     * @param CodeLocation[] $locations
     * @param array<array{start:int,end:int,replacement:string}> $edits
     */
    protected function applyEditsToFile(File $file, array $locations, string $contents, array $edits): string
    {
        $file->setCodeMap(new FileCodeMap($locations, $contents));
        $file->setPlannedEdits($edits);

        $config = $this->createMock(PrefixerConfigInterface::class);
        $prefixer = new Prefixer($config, $this->getInMemoryFileSystem(), $this->getLogger());

        return $prefixer->replaceInString(new DiscoveredSymbols(), $contents, $file);
    }

    /**
     * @covers ::replaceInString
     * @covers ::applyEdits
     * @covers ::updateCodeMap
     */
    public function testApplyEditsShiftsLaterLocations(): void
    {
        $contents = '<?php new Foo(); new Bar();';
        //                     10^      21^
        $fooLocation = new CodeLocation(CodeLocation::NAME, 10, 'Foo');
        $barLocation = new CodeLocation(CodeLocation::NAME, 21, 'Bar');
        $file = new File('/a/path.php', 'path.php', '/a/path.php');

        $result = $this->applyEditsToFile($file, [$fooLocation, $barLocation], $contents, [
            ['start' => 10, 'end' => 13, 'replacement' => 'Prefixed_Foo'],
        ]);

        self::assertSame('<?php new Prefixed_Foo(); new Bar();', $result);

        $map = $file->getCodeMap();
        [$updatedFoo, $updatedBar] = $map->getLocations();

        self::assertSame('Prefixed_Foo', $updatedFoo->getText());
        self::assertSame(10, $updatedFoo->getStart());

        self::assertSame('Bar', $updatedBar->getText());
        self::assertSame(30, $updatedBar->getStart());
        self::assertSame('Bar', substr($result, $updatedBar->getStart(), $updatedBar->getLength()));

        self::assertTrue($map->matchesContent($result));
        self::assertNull($file->getPlannedEdits());

        // The original map's locations are untouched.
        self::assertSame('Foo', $fooLocation->getText());
        self::assertSame(21, $barLocation->getStart());
    }

    /**
     * An edit inside a larger location — e.g. a classname inside a comment — is spliced into its text.
     *
     * @covers ::updateCodeMap
     */
    public function testApplyEditsSplicesEditInsideLocation(): void
    {
        $contents = '<?php /** @param Foo $foo */ $x = 1;';
        //                6^          17^
        $commentLocation = new CodeLocation(CodeLocation::DOC_COMMENT, 6, '/** @param Foo $foo */');
        $file = new File('/a/path.php', 'path.php', '/a/path.php');

        $result = $this->applyEditsToFile($file, [$commentLocation], $contents, [
            ['start' => 17, 'end' => 20, 'replacement' => 'Prefixed_Foo'],
        ]);

        self::assertSame('<?php /** @param Prefixed_Foo $foo */ $x = 1;', $result);

        [$updatedComment] = $file->getCodeMap()->getLocations();
        self::assertSame('/** @param Prefixed_Foo $foo */', $updatedComment->getText());
        self::assertSame(
            $updatedComment->getText(),
            substr($result, $updatedComment->getStart(), $updatedComment->getLength())
        );
    }

    /**
     * A location partially overlapped by an edit can no longer be tracked and is dropped.
     *
     * @covers ::updateCodeMap
     */
    public function testApplyEditsDropsPartiallyOverlappedLocation(): void
    {
        $contents = 'aaabbbccc';
        $location = new CodeLocation(CodeLocation::NAME, 3, 'bbb');
        $file = new File('/a/path.php', 'path.php', '/a/path.php');

        $result = $this->applyEditsToFile($file, [$location], $contents, [
            ['start' => 1, 'end' => 5, 'replacement' => 'X'],
        ]);

        self::assertSame('aXbccc', $result);
        self::assertSame([], $file->getCodeMap()->getLocations());
    }

    /**
     * A plan is only valid for the map it was made against.
     *
     * @covers \BrianHenryIE\Strauss\Files\File::setCodeMap
     */
    public function testReplacingCodeMapDiscardsPlan(): void
    {
        $file = new File('/a/path.php', 'path.php', '/a/path.php');
        $file->setCodeMap(new FileCodeMap([], 'a'));
        $file->setPlannedEdits([]);

        self::assertSame([], $file->getPlannedEdits());

        $file->setCodeMap(new FileCodeMap([], 'b'));

        self::assertNull($file->getPlannedEdits());
    }
}
