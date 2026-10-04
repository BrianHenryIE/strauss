<?php
/**
 * The planner looks up the names written in a comment or string in a {@see SymbolIndex}, rather than searching
 * each for every symbol. The names it finds, and the ones it leaves alone, must be the same as before.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Unit\Pipeline;

use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Pipeline\ChangePlanner;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\PhpFileAnalyzer;
use BrianHenryIE\Strauss\Pipeline\SymbolIndex;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\ChangePlanner
 */
class ChangePlannerSymbolIndexTest extends TestCase
{
    protected function getFile(): File
    {
        return new File(
            'vendor/acme/lib/src/file.php',
            'acme/lib/src/file.php',
            'vendor-prefixed/acme/lib/src/file.php'
        );
    }

    /**
     * Namespace `Acme\Lib` with a class and a function; a class and a function in the global namespace.
     */
    protected function getSymbols(): DiscoveredSymbols
    {
        $file = $this->getFile();

        $acme = new NamespaceSymbol('Acme\Lib', $file);
        $acme->setDoRename(true);
        $acme->setLocalReplacement('Prefix\Acme\Lib');

        $widget = new ClassSymbol('Acme\Lib\Widget', $file, $acme);
        $widget->setDoRename(true);

        $helper = new FunctionSymbol('Acme\Lib\helper', $file, $acme);
        $helper->setDoRename(true);

        $global = new NamespaceSymbol('\\', $file);

        $globalClass = new ClassSymbol('GlobalThing', $file, $global);
        $globalClass->setDoRename(true);
        $globalClass->setLocalReplacement('Prefix_GlobalThing');

        $globalFunction = new FunctionSymbol('value', $file, $global);
        $globalFunction->setDoRename(true);
        $globalFunction->setLocalReplacement('prefix_value');

        return new DiscoveredSymbols([$acme, $widget, $helper, $global, $globalClass, $globalFunction]);
    }

    /**
     * Plan the contents and apply the plan, so the tests assert on the resulting code.
     */
    protected function prefix(string $contents, DiscoveredSymbols $symbols, ?ChangePlanner $planner = null): string
    {
        $codeMap = (new PhpFileAnalyzer())->analyze($contents);
        self::assertNotNull($codeMap);

        $plan = ($planner ?? new ChangePlanner())->plan($symbols, $codeMap, $this->getFile());

        usort($plan, fn(array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($plan as $edit) {
            $contents = substr($contents, 0, $edit['start']) . $edit['replacement'] . substr($contents, $edit['end']);
        }

        return $contents;
    }

    /**
     * @covers ::findDocCommentPositions
     * @covers ::getIndex
     */
    public function test_doc_comment_names_are_looked_up(): void
    {
        $contents = <<<'EOD'
<?php
namespace Consumer;

/**
 * @param \Acme\Lib\Widget|\Other\Thing|null $widget
 * @return Acme\Lib\Widget[]
 */
function make($widget) {
}
EOD;
        $expected = <<<'EOD'
<?php
namespace Consumer;

/**
 * @param \Prefix\Acme\Lib\Widget|\Other\Thing|null $widget
 * @return Prefix\Acme\Lib\Widget[]
 */
function make($widget) {
}
EOD;

        self::assertSame($expected, $this->prefix($contents, $this->getSymbols()));
    }

    /**
     * A function's name is only replaced where it is clearly a reference to the function, not where it is a word.
     *
     * @covers ::findDocCommentPositions
     */
    public function test_doc_comment_functions_are_only_replaced_as_references(): void
    {
        $contents = <<<'EOD'
<?php
namespace Consumer;

/**
 * Makes a widget from a value.
 *
 * @see \Acme\Lib\helper()
 * @see value()
 */
function make() {
}
EOD;
        $expected = <<<'EOD'
<?php
namespace Consumer;

/**
 * Makes a widget from a value.
 *
 * @see \Prefix\Acme\Lib\helper()
 * @see prefix_value()
 */
function make() {
}
EOD;

        self::assertSame($expected, $this->prefix($contents, $this->getSymbols()));
    }

    /**
     * `\GlobalThing` is replaced in doc and line comments; a longer name which starts with it is not.
     *
     * @covers ::findGlobalSymbolsPositionsInComment
     * @covers ::findGlobalSymbolPositionInComment
     */
    public function test_global_symbols_in_comments_are_looked_up(): void
    {
        $contents = <<<'EOD'
<?php
namespace Consumer;

/**
 * @param \GlobalThing|\GlobalThingy $thing A \GlobalThing, twice: \GlobalThing.
 */
function make($thing) {
    // A \GlobalThing in a line comment, and \Unrelated.
}
EOD;
        $expected = <<<'EOD'
<?php
namespace Consumer;

/**
 * @param \Prefix_GlobalThing|\GlobalThingy $thing A \Prefix_GlobalThing, twice: \Prefix_GlobalThing.
 */
function make($thing) {
    // A \Prefix_GlobalThing in a line comment, and \Unrelated.
}
EOD;

        self::assertSame($expected, $this->prefix($contents, $this->getSymbols()));
    }

    /**
     * Only symbols whose first segment is a word in the file's strings are searched for; whole names only.
     *
     * @covers ::findSymbolsPositionsInStrings
     */
    public function test_strings_are_searched_for_the_symbols_they_could_contain(): void
    {
        $contents = <<<'EOD'
<?php
namespace Consumer;

return ['GlobalThing', 'GlobalThingy', 'Acme\\Lib\\Widget', 'Unrelated\\Name'];
EOD;
        $expected = <<<'EOD'
<?php
namespace Consumer;

return ['Prefix_GlobalThing', 'GlobalThingy', 'Prefix\\Acme\\Lib\\Widget', 'Unrelated\\Name'];
EOD;

        self::assertSame($expected, $this->prefix($contents, $this->getSymbols()));
    }

    /**
     * @covers ::findSymbolsPositionsInStrings
     */
    public function test_nothing_is_planned_when_no_symbol_is_named(): void
    {
        $contents = <<<'EOD'
<?php
namespace Consumer;

/**
 * @param \Unrelated\Name $name Not a widget.
 */
function make($name) {
    return 'Lib\\Widget';
}
EOD;
        $codeMap = (new PhpFileAnalyzer())->analyze($contents);
        self::assertNotNull($codeMap);

        self::assertSame([], (new ChangePlanner())->plan($this->getSymbols(), $codeMap, $this->getFile()));
    }

    /**
     * The index is built once for a collection of symbols, and rebuilt when a different collection is passed.
     *
     * @covers ::getIndex
     */
    public function test_index_is_reused_for_the_same_symbols_and_rebuilt_for_others(): void
    {
        $planner = new class () extends ChangePlanner {
            public function getIndex(DiscoveredSymbols $discoveredSymbols): SymbolIndex
            {
                return parent::getIndex($discoveredSymbols);
            }
        };

        $symbols = $this->getSymbols();
        $otherSymbols = $this->getSymbols();

        $index = $planner->getIndex($symbols);

        self::assertSame($index, $planner->getIndex($symbols));

        $otherIndex = $planner->getIndex($otherSymbols);

        self::assertNotSame($index, $otherIndex);
        self::assertTrue($otherIndex->isFor($otherSymbols));
    }

    /**
     * One planner used with different symbols, as {@see \BrianHenryIE\Strauss\Pipeline\Prefixer::replaceInString()}
     * may, plans against the symbols it was given each time.
     *
     * @covers ::plan
     * @covers ::getIndex
     */
    public function test_planner_reused_with_different_symbols(): void
    {
        $contents = <<<'EOD'
<?php
namespace Consumer;

/** @var \Acme\Lib\Widget $widget */
$widget = new \Acme\Lib\Widget();
EOD;
        $expected = <<<'EOD'
<?php
namespace Consumer;

/** @var \Prefix\Acme\Lib\Widget $widget */
$widget = new \Prefix\Acme\Lib\Widget();
EOD;

        $planner = new ChangePlanner();

        self::assertSame($expected, $this->prefix($contents, $this->getSymbols(), $planner));
        self::assertSame($contents, $this->prefix($contents, new DiscoveredSymbols(), $planner));
        self::assertSame($expected, $this->prefix($contents, $this->getSymbols(), $planner));
    }

    /**
     * Namespaces `Acme`, `Acme\Lib` and `Acme\Lib\Widget`, and a class `Acme\Lib\Widget` which shares a name with
     * the last of them.
     */
    protected function getNestedSymbols(): DiscoveredSymbols
    {
        $file = $this->getFile();

        $namespaces = [];
        foreach (['Acme', 'Acme\Lib', 'Acme\Lib\Widget'] as $name) {
            $namespaces[$name] = new NamespaceSymbol($name, $file);
            $namespaces[$name]->setDoRename(true);
            $namespaces[$name]->setLocalReplacement('Prefix\\' . $name);
        }

        $root = new ClassSymbol('Acme\Root', $file, $namespaces['Acme']);
        $root->setDoRename(true);
        $widget = new ClassSymbol('Acme\Lib\Widget', $file, $namespaces['Acme\Lib']);
        $widget->setDoRename(true);
        $part = new ClassSymbol('Acme\Lib\Widget\Part', $file, $namespaces['Acme\Lib\Widget']);
        $part->setDoRename(true);

        return new DiscoveredSymbols(array_merge(array_values($namespaces), [$root, $widget, $part]));
    }

    /**
     * A `use` of a class which was not discovered is renamed by the namespaces it is in; a name which only contains
     * a namespace's name is not.
     *
     * @covers ::findUseStatementPositionsForNamespacedClasses
     */
    public function test_use_statements_in_nested_namespaces(): void
    {
        $contents = <<<'EOD'
<?php
namespace Consumer;

use Acme\Lib\Widget;
use Acme\Lib\Unknown;
use Acme\Lib\Widget\Deeper\Thing;
use Acme\Lib\{Widget as W, Other};
use Unrelated\Acme\Lib\Widget as U;
use AcmeNot\Lib\Widget as N;
EOD;
        $expected = <<<'EOD'
<?php
namespace Consumer;

use Prefix\Acme\Lib\Widget;
use Prefix\Acme\Lib\Unknown;
use Prefix\Acme\Lib\Widget\Deeper\Thing;
use Prefix\Acme\Lib\{Widget as W, Other};
use Unrelated\Acme\Lib\Widget as U;
use AcmeNot\Lib\Widget as N;
EOD;

        self::assertSame($expected, $this->prefix($contents, $this->getNestedSymbols()));
    }

    /**
     * Only a whole quoted name is replaced. When a namespace and a class share the name, the namespace's forms
     * (a trailing backslash, a variable class name) are replaced as well as the class's (a static property).
     *
     * @covers ::findSymbolsPositionsInStrings
     * @covers ::findSymbolPositionsInStrings
     */
    public function test_strings_naming_a_namespace_and_class_with_the_same_name(): void
    {
        $contents = <<<'EOD'
<?php
namespace Consumer;

$a = ['Acme\\Lib\\Widget', 'Acme\\Lib\\Widget\\', "Acme\Lib\Widget\\$name", 'Acme\Lib\Widget::$instance'];
$b = ['Acme\\Lib\\WidgetFactory', 'the Acme\\Lib\\Widget class', 'Lib\\Widget'];
EOD;
        $expected = <<<'EOD'
<?php
namespace Consumer;

$a = ['Prefix\\Acme\\Lib\\Widget', 'Prefix\\Acme\\Lib\\Widget\\', "Prefix\Acme\Lib\Widget\\$name", 'Prefix\Acme\Lib\Widget::$instance'];
$b = ['Acme\\Lib\\WidgetFactory', 'the Acme\\Lib\\Widget class', 'Lib\\Widget'];
EOD;

        self::assertSame($expected, $this->prefix($contents, $this->getNestedSymbols()));
    }

    /**
     * A file which mentions a vendor namespace everywhere, as a licence header does, is only searched for the
     * symbols it quotes.
     *
     * @covers ::findSymbolsPositionsInStrings
     */
    public function test_strings_are_only_searched_for_quoted_names(): void
    {
        $planner = new class () extends ChangePlanner {
            /** @var string[] */
            public array $searchedFor = [];

            protected function findSymbolPositionsInStrings(string $contents, \BrianHenryIE\Strauss\Types\DiscoveredSymbol $symbol): array
            {
                $this->searchedFor[] = get_class($symbol) . ':' . $symbol->getOriginalFqdnName();
                return parent::findSymbolPositionsInStrings($contents, $symbol);
            }
        };

        $contents = <<<'EOD'
<?php
/*
 * This file is part of the Acme package. See Acme\Lib and Acme\Root.
 */
namespace Consumer;

return is_a($thing, 'Acme\\Lib\\Widget\\Part');
EOD;

        $this->prefix($contents, $this->getNestedSymbols(), $planner);

        self::assertSame([ClassSymbol::class . ':Acme\Lib\Widget\Part'], $planner->searchedFor);
    }
}
