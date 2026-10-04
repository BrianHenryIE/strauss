<?php

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Unit\Pipeline;

use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Pipeline\SymbolIndex;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\SymbolIndex
 */
class SymbolIndexTest extends TestCase
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
     * A renamed namespace `Acme\Lib` with a class and a function, a namespace which is not renamed with a class,
     * and a renamed class and constant in the global namespace.
     *
     * @return array<string, DiscoveredSymbol>
     */
    protected function getSymbols(): array
    {
        $file = $this->getFile();

        $acme = new NamespaceSymbol('Acme\Lib', $file);
        $acme->setDoRename(true);
        $acme->setLocalReplacement('Prefix\Acme\Lib');

        $widget = new ClassSymbol('Acme\Lib\Widget', $file, $acme);
        $widget->setDoRename(true);

        $helper = new FunctionSymbol('Acme\Lib\helper', $file, $acme);
        $helper->setDoRename(true);

        $other = new NamespaceSymbol('Other\Lib', $file);
        $otherClass = new ClassSymbol('Other\Lib\Untouched', $file, $other);

        $global = new NamespaceSymbol('\\', $file);

        $globalClass = new ClassSymbol('GlobalThing', $file, $global);
        $globalClass->setDoRename(true);
        $globalClass->setLocalReplacement('Prefix_GlobalThing');

        $constant = new ConstantSymbol('ACME_VERSION', $file, $global);
        $constant->setDoRename(true);
        $constant->setLocalReplacement('PREFIX_ACME_VERSION');

        return [
            'acme' => $acme,
            'widget' => $widget,
            'helper' => $helper,
            'other' => $other,
            'otherClass' => $otherClass,
            'global' => $global,
            'globalClass' => $globalClass,
            'constant' => $constant,
        ];
    }

    /**
     * @param DiscoveredSymbols|DiscoveredSymbol[] $symbols
     *
     * @return string[] Sorted.
     */
    protected function names($symbols): array
    {
        $names = [];
        foreach ($symbols as $symbol) {
            $names[] = $symbol->getOriginalFqdnName();
        }
        sort($names);
        return $names;
    }

    /**
     * @covers ::__construct
     * @covers ::getNamespacesToRename
     * @covers ::getNamespacedToRename
     * @covers ::getNamespacedToRenameNotGlobal
     * @covers ::getGlobalClassesInterfacesTraitsToRename
     * @covers ::getFunctionsToRename
     * @covers ::getConstantsToRename
     */
    public function test_groups_only_contain_symbols_to_rename(): void
    {
        $sut = new SymbolIndex(new DiscoveredSymbols(array_values($this->getSymbols())));

        self::assertSame(['Acme\Lib'], $this->names($sut->getNamespacesToRename()));
        self::assertSame(
            ['ACME_VERSION', 'Acme\Lib\Widget', 'Acme\Lib\helper', 'GlobalThing'],
            $this->names($sut->getNamespacedToRename())
        );
        self::assertSame(
            ['Acme\Lib\Widget', 'Acme\Lib\helper'],
            $this->names($sut->getNamespacedToRenameNotGlobal())
        );
        self::assertSame(
            ['ACME_VERSION', 'GlobalThing'],
            $this->names($sut->getGlobalClassesInterfacesTraitsToRename())
        );
        self::assertSame(['Acme\Lib\helper'], $this->names($sut->getFunctionsToRename()));
        self::assertSame(['ACME_VERSION'], $this->names($sut->getConstantsToRename()));
    }

    /**
     * Functions are looked up separately from the other namespaced symbols, since they are only replaced in
     * comments when clearly a function reference.
     *
     * @covers ::__construct
     * @covers ::getNamespacedToRenameByFqdn
     * @covers ::getFunctionToRenameByFqdn
     * @covers ::getGlobalClassInterfaceTraitToRenameByName
     */
    public function test_lookups_by_name(): void
    {
        $symbols = $this->getSymbols();
        $sut = new SymbolIndex(new DiscoveredSymbols(array_values($symbols)));

        self::assertSame($symbols['widget'], $sut->getNamespacedToRenameByFqdn('Acme\Lib\Widget'));
        self::assertSame($symbols['globalClass'], $sut->getNamespacedToRenameByFqdn('GlobalThing'));
        self::assertNull($sut->getNamespacedToRenameByFqdn('Acme\Lib\helper'), 'Functions are indexed separately.');
        self::assertNull($sut->getNamespacedToRenameByFqdn('Other\Lib\Untouched'), 'Not being renamed.');
        self::assertNull($sut->getNamespacedToRenameByFqdn('Acme\Lib'), 'A namespace is not a namespaced symbol.');

        self::assertSame($symbols['helper'], $sut->getFunctionToRenameByFqdn('Acme\Lib\helper'));
        self::assertNull($sut->getFunctionToRenameByFqdn('Acme\Lib\Widget'));
        self::assertNull($sut->getFunctionToRenameByFqdn('helper'));

        self::assertSame($symbols['globalClass'], $sut->getGlobalClassInterfaceTraitToRenameByName('GlobalThing'));
        self::assertNull($sut->getGlobalClassInterfaceTraitToRenameByName('Widget'));
        self::assertNull($sut->getGlobalClassInterfaceTraitToRenameByName('Missing'));
    }

    /**
     * A symbol is a candidate when the name it is searched for by is quoted in the text; each is returned once.
     *
     * @covers ::__construct
     * @covers ::getStringSearchSymbolsForNames
     */
    public function test_string_search_symbols_are_found_by_name(): void
    {
        $symbols = $this->getSymbols();
        $sut = new SymbolIndex(new DiscoveredSymbols(array_values($symbols)));

        self::assertSame(['Acme\Lib'], $this->names($sut->getStringSearchSymbolsForNames(['Acme\Lib' => true])));
        self::assertSame(
            ['Acme\Lib\Widget', 'Acme\Lib\helper'],
            $this->names($sut->getStringSearchSymbolsForNames(['Acme\Lib\Widget' => true, 'Acme\Lib\helper' => true]))
        );

        // Symbols in the global namespace are searched for by their local name.
        self::assertSame(
            ['ACME_VERSION', 'GlobalThing'],
            $this->names($sut->getStringSearchSymbolsForNames(['GlobalThing' => true, 'ACME_VERSION' => true]))
        );

        // Part of a name, and names which are not being renamed, find nothing.
        self::assertSame(
            [],
            $sut->getStringSearchSymbolsForNames(['Acme' => true, 'Widget' => true, 'Other\Lib\Untouched' => true])
        );
        self::assertSame([], $sut->getStringSearchSymbolsForNames([]));
    }

    /**
     * A namespace and a class with the same name are both candidates, the namespace first.
     *
     * @covers ::__construct
     * @covers ::getStringSearchSymbolsForNames
     */
    public function test_string_search_returns_every_symbol_with_the_name(): void
    {
        $symbols = $this->getSymbols();

        $widgetNamespace = new NamespaceSymbol('Acme\Lib\Widget', $this->getFile());
        $widgetNamespace->setDoRename(true);
        $widgetNamespace->setLocalReplacement('Prefix\Acme\Lib\Widget');
        $symbols['widgetNamespace'] = $widgetNamespace;

        $sut = new SymbolIndex(new DiscoveredSymbols(array_values($symbols)));

        self::assertSame(
            [$widgetNamespace, $symbols['widget']],
            $sut->getStringSearchSymbolsForNames(['Acme\Lib\Widget' => true])
        );
    }

    /**
     * @covers ::__construct
     * @covers ::getStringSearchSymbolsForNames
     */
    public function test_string_search_excludes_symbols_not_replaced_in_strings(): void
    {
        $symbols = $this->getSymbols();
        $symbols['helper']->setReplaceInString(false);
        $symbols['globalClass']->setReplaceInString(false);

        $sut = new SymbolIndex(new DiscoveredSymbols(array_values($symbols)));

        self::assertSame(
            ['Acme\Lib\Widget'],
            $this->names($sut->getStringSearchSymbolsForNames([
                'Acme\Lib\Widget' => true,
                'Acme\Lib\helper' => true,
                'GlobalThing' => true,
            ]))
        );
    }

    /**
     * The global namespace `\` has no name to search strings for.
     *
     * @covers ::__construct
     * @covers ::getStringSearchSymbolsForNames
     */
    public function test_string_search_excludes_the_global_namespace(): void
    {
        $symbols = $this->getSymbols();
        $symbols['global']->setDoRename(true);
        $symbols['global']->setLocalReplacement('Prefix');

        $sut = new SymbolIndex(new DiscoveredSymbols(array_values($symbols)));

        self::assertContains('\\', $this->names($sut->getNamespacesToRename()));
        self::assertSame([], $sut->getStringSearchSymbolsForNames(['\\' => true, '' => true]));
    }

    /**
     * `"\0Composer\Autoload\ClassLoader\0"` is not a quoted name, so that namespace is always a candidate.
     *
     * @covers ::__construct
     * @covers ::getStringSearchSymbolsForNames
     */
    public function test_string_search_always_includes_the_composer_autoload_namespace(): void
    {
        $name = implode('\\', ['Comp' . 'oser', 'Autoload']);
        $composerAutoload = new NamespaceSymbol($name, $this->getFile());
        $composerAutoload->setDoRename(true);
        $composerAutoload->setLocalReplacement('Prefix\\' . $name);

        $symbols = $this->getSymbols();
        $symbols['composerAutoload'] = $composerAutoload;

        $sut = new SymbolIndex(new DiscoveredSymbols(array_values($symbols)));

        self::assertSame([$composerAutoload], $sut->getStringSearchSymbolsForNames([]));
        self::assertSame([$composerAutoload], $sut->getStringSearchSymbolsForNames([$name => true]), 'Once.');
    }

    /**
     * @covers ::getNamespaces
     * @covers ::getSymbolCount
     */
    public function test_all_namespaces_and_the_symbol_count(): void
    {
        $sut = new SymbolIndex(new DiscoveredSymbols(array_values($this->getSymbols())));

        self::assertSame(['Acme\Lib', 'Other\Lib', '\\'], $this->names($sut->getNamespaces()));
        self::assertSame(8, $sut->getSymbolCount());
    }

    /**
     * @covers ::getNamespacesToRenameByName
     */
    public function test_namespaces_to_rename_by_name_longest_first(): void
    {
        $symbols = $this->getSymbols();

        $deeper = new NamespaceSymbol('Acme\Lib\Deeper', $this->getFile());
        $deeper->setDoRename(true);
        $deeper->setLocalReplacement('Prefix\Acme\Lib\Deeper');
        $symbols['deeper'] = $deeper;

        $sut = new SymbolIndex(new DiscoveredSymbols(array_values($symbols)));

        self::assertSame(
            ['Acme\Lib\Deeper' => $deeper, 'Acme\Lib' => $symbols['acme']],
            $sut->getNamespacesToRenameByName()
        );
    }

    /**
     * Only namespaces with a symbol being renamed are "active"; the global namespace never is.
     *
     * @covers ::getActiveNamespaceReplacements
     */
    public function test_active_namespace_replacements_longest_first(): void
    {
        $symbols = $this->getSymbols();

        $deeper = new NamespaceSymbol('Acme\Lib\Deeper', $this->getFile());
        $deeper->setDoRename(true);
        $deeper->setLocalReplacement('Prefix\Acme\Lib\Deeper');
        $deeperClass = new ClassSymbol('Acme\Lib\Deeper\Thing', $this->getFile(), $deeper);
        $deeperClass->setDoRename(true);

        $empty = new NamespaceSymbol('Acme\Empty', $this->getFile());
        $empty->setDoRename(true);
        $empty->setLocalReplacement('Prefix\Acme\Empty');

        $sut = new SymbolIndex(new DiscoveredSymbols(
            array_merge(array_values($symbols), [$deeper, $deeperClass, $empty])
        ));

        self::assertSame(
            ['Acme\Lib\Deeper' => 'Prefix\Acme\Lib\Deeper', 'Acme\Lib' => 'Prefix\Acme\Lib'],
            $sut->getActiveNamespaceReplacements()
        );
    }

    /**
     * @covers ::isFor
     */
    public function test_is_for_the_same_collection_instance_only(): void
    {
        $discoveredSymbols = new DiscoveredSymbols(array_values($this->getSymbols()));
        $sut = new SymbolIndex($discoveredSymbols);

        self::assertTrue($sut->isFor($discoveredSymbols));
        self::assertFalse($sut->isFor(new DiscoveredSymbols(array_values($this->getSymbols()))));
    }

    /**
     * The name after each quote, with at most two backslashes before it, escaped backslashes as single ones.
     *
     * @covers ::getQuotedNames
     */
    public function test_get_quoted_names(): void
    {
        $text = <<<'EOD'
 'Acme\\Lib\\Widget::$instance' "\\Acme\Lib\\" 'GlobalThing' "Acme\Lib\$class" 'two words' unquoted\Name
EOD;

        self::assertSame(
            ['Acme\Lib\Widget', 'Acme\Lib', 'GlobalThing', 'two'],
            array_keys(SymbolIndex::getQuotedNames($text))
        );
        self::assertSame([], SymbolIndex::getQuotedNames(''));
        self::assertSame([], SymbolIndex::getQuotedNames('No\Quotes here'));
        self::assertSame([], SymbolIndex::getQuotedNames("'\\\\\\Three\\Backslashes'"));
    }

    /**
     * @covers ::getQualifiedNameCandidates
     */
    public function test_get_qualified_name_candidates(): void
    {
        self::assertSame(
            ['Foo', 'Foo\Bar', 'Foo\Bar\Baz', 'Bar', 'Bar\Baz', 'Baz'],
            array_keys(SymbolIndex::getQualifiedNameCandidates('\Foo\Bar\Baz'))
        );
    }

    /**
     * Each run of names in a comment is split separately; escaped backslashes do not produce empty segments.
     *
     * @covers ::getQualifiedNameCandidates
     */
    public function test_get_qualified_name_candidates_in_a_comment(): void
    {
        $text = <<<'EOD'
/**
 * @param \Acme\Widget|null $widget See `Acme\\Widget` and helper().
 */
EOD;

        $candidates = SymbolIndex::getQualifiedNameCandidates($text);

        self::assertArrayHasKey('Acme\Widget', $candidates);
        self::assertArrayHasKey('Acme', $candidates);
        self::assertArrayHasKey('Widget', $candidates);
        self::assertArrayHasKey('helper', $candidates);
        self::assertArrayHasKey('null', $candidates);
        self::assertArrayNotHasKey('', $candidates);
        self::assertArrayNotHasKey('Widget\null', $candidates, 'Names separated by `|` are not joined.');
        self::assertSame([], SymbolIndex::getQualifiedNameCandidates(' * | '));
    }
}
