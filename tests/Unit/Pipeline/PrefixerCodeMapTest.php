<?php
/**
 * After a file has its symbols prefixed, the File object's code map — its parsed data — is updated to reflect the
 * changes, rather than discarded, so the file never needs to be parsed again.
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Config\PrefixerConfigInterface;
use BrianHenryIE\Strauss\Files\CodeLocation;
use BrianHenryIE\Strauss\Files\File;
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
        (new ChangePlanner())->plan($discoveredSymbols, $codeMap, $file);

        $config = $this->createMock(PrefixerConfigInterface::class);
        $prefixer = new Prefixer($config, $this->getInMemoryFileSystem(), $this->getLogger());

        // An empty symbol set: the stored plan, not the symbols passed, determines the result.
        $result = $prefixer->replaceInString(new DiscoveredSymbols(), $contents, $file);

        self::assertStringContainsString('namespace Prefix\Vendor\Package;', $result);
        self::assertSame($codeMap, $file->getCodeMap());
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
}
