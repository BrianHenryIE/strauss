<?php

namespace BrianHenryIE\Strauss\Pipeline\FileSymbol;

use BrianHenryIE\Strauss\Files\CodeLocation;
use BrianHenryIE\Strauss\Files\FileCodeMap;
use BrianHenryIE\Strauss\TestCase;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\FileSymbol\PhpFileAnalyzer
 */
class PhpFileAnalyzerTest extends TestCase
{
    protected function analyze(string $contents): FileCodeMap
    {
        $codeMap = (new PhpFileAnalyzer())->analyze($contents);
        self::assertNotNull($codeMap);
        return $codeMap;
    }

    /**
     * Every recorded location's text must be exactly what is at that position in the file.
     */
    protected function assertLocationsMatchContents(FileCodeMap $codeMap, string $contents): void
    {
        self::assertTrue($codeMap->matchesContent($contents));
        foreach ($codeMap->getLocations() as $location) {
            self::assertSame(
                $location->getText(),
                substr($contents, $location->getStart(), $location->getLength()),
                "Location of type {$location->getType()} does not match the file contents."
            );
        }
    }

    /**
     * @covers ::analyze
     */
    public function testRecordsDefinitionsAndUsagesWithPositions(): void
    {
        $contents = <<<'EOD'
<?php

namespace My\Ns;

use Other\Package\TheirClass;
use function Other\Package\their_function;

class MyClass extends TheirClass implements \Countable {
    public function count(): int {
        return \Other\Package\CONSTANT + their_function();
    }
}
EOD;

        $map = $this->analyze($contents);
        $this->assertLocationsMatchContents($map, $contents);

        self::assertSame(['My\Ns'], $map->getNamespaceNames());

        $namespaceDefinitions = $map->getLocationsOfType(CodeLocation::NAMESPACE_DEFINITION);
        self::assertCount(1, $namespaceDefinitions);
        self::assertSame('My\Ns', $namespaceDefinitions[0]->getText());

        $classDefinitions = $map->getLocationsOfType(CodeLocation::CLASS_DEFINITION);
        self::assertCount(1, $classDefinitions);
        self::assertSame('MyClass', $classDefinitions[0]->getText());

        $useNames = $map->getLocationsOfType(CodeLocation::USE_NAME);
        self::assertCount(2, $useNames);
        self::assertSame('Other\Package\TheirClass', $useNames[0]->getText());
        self::assertSame(CodeLocation::USE_TYPE_NORMAL, $useNames[0]->getUseType());
        self::assertSame('Other\Package\their_function', $useNames[1]->getText());
        self::assertSame(CodeLocation::USE_TYPE_FUNCTION, $useNames[1]->getUseType());

        $names = $map->getLocationsOfType(CodeLocation::NAME);
        $nameTexts = array_map(fn(CodeLocation $location) => $location->getText(), $names);
        self::assertContains('TheirClass', $nameTexts); // In `extends`.
        self::assertContains('\Countable', $nameTexts); // In `implements`.

        // The alias import resolves the `extends TheirClass` usage.
        foreach ($names as $name) {
            if ('TheirClass' === $name->getText()) {
                self::assertSame('Other\Package\TheirClass', $name->getResolvedName());
            }
        }

        $constFetches = $map->getLocationsOfType(CodeLocation::CONST_FETCH);
        self::assertCount(1, $constFetches);
        self::assertSame('\Other\Package\CONSTANT', $constFetches[0]->getText());
        self::assertTrue($constFetches[0]->isFullyQualified());
        self::assertSame('Other\Package\CONSTANT', $constFetches[0]->getName());

        $functionCalls = $map->getLocationsOfType(CodeLocation::FUNCTION_CALL);
        self::assertCount(1, $functionCalls);
        self::assertSame('their_function', $functionCalls[0]->getText());

        // The namespace and the class definitions.
        $definitions = $map->getDefinitions();
        self::assertCount(2, $definitions);
        self::assertSame(SymbolDefinition::TYPE_NAMESPACE, $definitions[0]->getType());
        self::assertSame('My\Ns', $definitions[0]->getName());
        self::assertSame(SymbolDefinition::TYPE_CLASS, $definitions[1]->getType());
        self::assertSame('My\Ns\MyClass', $definitions[1]->getName());
        self::assertSame('Other\Package\TheirClass', $definitions[1]->getExtends());
        self::assertSame(['Countable'], $definitions[1]->getInterfaces());
    }

    /**
     * Comments and strings are recorded so replacements inside them can be targeted.
     *
     * @covers ::analyze
     */
    public function testRecordsCommentsAndStrings(): void
    {
        $contents = <<<'EOD'
<?php
// A line comment mentioning \My\Ns\MyClass.
/** A doc comment. */
$a = 'My\Ns\MyClass';
$b = "interpolated $a string";
$c = 'My\Ns\\' . $a . 'suffix';
EOD;

        $map = $this->analyze($contents);
        $this->assertLocationsMatchContents($map, $contents);

        $comments = $map->getLocationsOfType(CodeLocation::COMMENT);
        self::assertCount(1, $comments);
        self::assertSame('// A line comment mentioning \My\Ns\MyClass.', $comments[0]->getText());

        $docComments = $map->getLocationsOfType(CodeLocation::DOC_COMMENT);
        self::assertCount(1, $docComments);
        self::assertSame('/** A doc comment. */', $docComments[0]->getText());

        $strings = $map->getLocationsOfType(CodeLocation::STRING);
        $stringTexts = array_map(fn(CodeLocation $location) => $location->getText(), $strings);
        self::assertSame(["'My\\Ns\\MyClass'", '"interpolated $a string"', "'My\\Ns\\\\'", "'suffix'"], $stringTexts);
    }

    /**
     * A fragment without an opening `<?php` tag is parsed as PHP, with positions still relative to the fragment.
     *
     * @covers ::analyze
     */
    public function testFragmentWithoutOpeningTag(): void
    {
        $contents = 'MY_CONSTANT; my_function();';

        $map = $this->analyze($contents);
        $this->assertLocationsMatchContents($map, $contents);

        $constFetches = $map->getLocationsOfType(CodeLocation::CONST_FETCH);
        self::assertCount(1, $constFetches);
        self::assertSame(0, $constFetches[0]->getStart());

        $functionCalls = $map->getLocationsOfType(CodeLocation::FUNCTION_CALL);
        self::assertCount(1, $functionCalls);
        self::assertSame('my_function', $functionCalls[0]->getText());

        self::assertSame(['\\'], $map->getNamespaceNames());
    }

    /**
     * Content before the open tag (e.g. a test fixture's leading whitespace) is skipped, and positions are still
     * relative to the original contents.
     *
     * @covers ::analyze
     */
    public function testContentBeforeOpeningTag(): void
    {
        $contents = "\n    <?php\n\tnamespace My\\Ns {\n\t\tclass A_Class { }\n\t}\n";

        $map = $this->analyze($contents);
        $this->assertLocationsMatchContents($map, $contents);

        self::assertSame(['My\Ns'], $map->getNamespaceNames());
        self::assertSame([], $map->getLocationsOfType(CodeLocation::INLINE_HTML));

        $classDefinitions = $map->getDefinitions(SymbolDefinition::TYPE_CLASS);
        self::assertCount(1, $classDefinitions);
        self::assertSame('My\Ns\A_Class', $classDefinitions[0]->getName());
    }

    /**
     * @covers ::analyze
     */
    public function testDefineIsRecordedAsConstantDefinition(): void
    {
        $contents = <<<'EOD'
<?php
namespace My\Ns;
define('MY_DEFINED', 1);
const MY_CONST = 2;
if (!defined('OTHER')) {}
EOD;

        $map = $this->analyze($contents);

        $constants = $map->getDefinitions(SymbolDefinition::TYPE_CONSTANT);
        self::assertCount(2, $constants);

        // As written, in the namespace; ConstantSymbol adds the namespace.
        self::assertSame('MY_DEFINED', $constants[0]->getName());
        self::assertSame('My\Ns', $constants[0]->getNamespace());
        self::assertSame('MY_CONST', $constants[1]->getName());

        $defineArgs = $map->getLocationsOfType(CodeLocation::DEFINE_STRING_ARG);
        self::assertCount(2, $defineArgs);
        self::assertSame('MY_DEFINED', $defineArgs[0]->getText());
        self::assertSame('OTHER', $defineArgs[1]->getName());
    }

    /**
     * @covers ::analyze
     */
    public function testEnumAndInterfaceDefinitions(): void
    {
        $contents = <<<'EOD'
<?php
namespace My\Ns;
interface HasLabel extends \Stringable {}
enum Status: string implements HasLabel { case Ready = 'ready'; }
trait Loggable {}
function helper() {}
EOD;

        $map = $this->analyze($contents);

        $byType = $map->getDefinitionsByType();

        self::assertSame('My\Ns\HasLabel', $byType[SymbolDefinition::TYPE_INTERFACE][0]->getName());
        self::assertSame(['Stringable'], $byType[SymbolDefinition::TYPE_INTERFACE][0]->getInterfaces());

        $enum = $byType[SymbolDefinition::TYPE_ENUM][0];
        self::assertSame('My\Ns\Status', $enum->getName());
        self::assertSame('string', $enum->getBackingType());
        self::assertSame(['My\Ns\HasLabel'], $enum->getInterfaces());

        self::assertSame('My\Ns\Loggable', $byType[SymbolDefinition::TYPE_TRAIT][0]->getName());
        self::assertSame('My\Ns\helper', $byType[SymbolDefinition::TYPE_FUNCTION][0]->getName());
    }

    /**
     * @covers ::analyze
     * @covers ::getLastErrorMessage
     */
    public function testParseErrorReturnsNull(): void
    {
        $analyzer = new PhpFileAnalyzer();

        self::assertNull($analyzer->analyze("<?php function broken( {\n"));
        self::assertNotNull($analyzer->getLastErrorMessage());

        self::assertNotNull($analyzer->analyze('<?php $ok = 1;'));
        self::assertNull($analyzer->getLastErrorMessage());
    }
}
