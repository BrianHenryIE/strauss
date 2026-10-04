<?php
/**
 * The symbols marked for renaming, grouped and indexed once per run for {@see ChangePlanner}.
 *
 * Planning a file must not cost O(symbols): a large project has tens of thousands of symbols and files. The
 * planner finds the names present in a file (or comment, or string) and looks them up here, rather than looking
 * for every symbol in every file.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\NamespacedSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Exception;

class SymbolIndex
{
    protected DiscoveredSymbols $discoveredSymbols;

    /** Every namespace, including those not being renamed. */
    protected DiscoveredSymbols $namespaces;

    protected int $symbolCount;

    protected DiscoveredSymbols $namespacesToRename;

    /**
     * @var ?array<string, NamespaceSymbol> Namespaces to rename, by name without a trailing `\`, longest first.
     */
    protected ?array $namespacesToRenameByName = null;

    /**
     * @var ?array<string, string> Original => replacement of each namespace which has a symbol being renamed,
     *                             longest first.
     */
    protected ?array $activeNamespaceReplacements = null;

    protected DiscoveredSymbols $namespacedToRename;

    protected DiscoveredSymbols $namespacedToRenameNotGlobal;

    protected DiscoveredSymbols $globalClassesInterfacesTraitsToRename;

    protected DiscoveredSymbols $functionsToRename;

    protected DiscoveredSymbols $constantsToRename;

    /** @var array<string, NamespacedSymbol> Namespaced (incl. global) symbols to rename, except functions, by FQDN. */
    protected array $namespacedToRenameByFqdn = [];

    /** @var array<string, FunctionSymbol> By FQDN. */
    protected array $functionsToRenameByFqdn = [];

    /** @var array<string, NamespacedSymbol> Global classes, interfaces, traits and enums to rename, by name. */
    protected array $globalClassesInterfacesTraitsToRenameByName = [];

    /**
     * The symbols searched for in strings, by the name searched for.
     *
     * @var array<string, DiscoveredSymbol[]>
     */
    protected array $stringSearchSymbolsByName = [];

    /**
     * Namespaces whose special case is not a quoted name: {@see ChangePlanner::findComposerClassLoaderPrefixPositions()}.
     *
     * @var DiscoveredSymbol[]
     */
    protected array $stringSearchSymbolsAlways = [];

    public function __construct(DiscoveredSymbols $discoveredSymbols)
    {
        $this->discoveredSymbols = $discoveredSymbols;

        $this->namespaces = $discoveredSymbols->getNamespaces();
        $this->symbolCount = count($discoveredSymbols->toArray());
        $this->namespacesToRename = $this->namespaces->getToRename();
        $this->namespacedToRename = $discoveredSymbols->getNamespacedSymbols()->getToRename();
        $this->namespacedToRenameNotGlobal = $this->namespacedToRename->notGlobal();
        $this->globalClassesInterfacesTraitsToRename = $discoveredSymbols->getGlobalClassesInterfacesTraits()->getToRename();
        $this->functionsToRename = $discoveredSymbols->getDiscoveredFunctions()->getToRename();
        $this->constantsToRename = $discoveredSymbols->getConstants()->getToRename();

        foreach ($this->namespacedToRename as $symbol) {
            if ($symbol instanceof FunctionSymbol) {
                $this->functionsToRenameByFqdn[$symbol->getOriginalFqdnName()] = $symbol;
            } elseif ($symbol instanceof NamespacedSymbol) {
                $this->namespacedToRenameByFqdn[$symbol->getOriginalFqdnName()] = $symbol;
            }
        }

        foreach ($this->globalClassesInterfacesTraitsToRename as $symbol) {
            if ($symbol instanceof NamespacedSymbol) {
                $this->globalClassesInterfacesTraitsToRenameByName[$symbol->getOriginalFqdnName()] = $symbol;
            }
        }

        // By value: a namespace and a class may share a name, and merging by key would drop the namespace.
        /** @var DiscoveredSymbol $symbol */
        foreach (array_merge(
            array_values($this->namespacesToRename->toArray()),
            array_values($this->namespacedToRename->toArray())
        ) as $symbol) {
            if (!$symbol->isReplaceInString()) {
                continue;
            }
            // Namespaces are searched for by FQDN; symbols in the global namespace by their local name.
            $name = ($symbol instanceof NamespacedSymbol && $symbol->getNamespace()->isGlobal())
                ? $symbol->getOriginalLocalName()
                : $symbol->getOriginalFqdnName();
            if ($symbol instanceof NamespaceSymbol && $symbol->isGlobal()) {
                continue;
            }
            $this->stringSearchSymbolsByName[$name][] = $symbol;
            // `"\0Composer\Autoload\ClassLoader\0"`. Concatenated so it is not replaced when Strauss prefixes itself.
            if ($symbol instanceof NamespaceSymbol && str_ends_with($name, implode('\\', ['Comp' . 'oser', 'Autoload']))) {
                $this->stringSearchSymbolsAlways[] = $symbol;
            }
        }
    }

    public function isFor(DiscoveredSymbols $discoveredSymbols): bool
    {
        return $this->discoveredSymbols === $discoveredSymbols;
    }

    public function getNamespaces(): DiscoveredSymbols
    {
        return $this->namespaces;
    }

    /**
     * The number of distinctly named symbols, for logging.
     */
    public function getSymbolCount(): int
    {
        return $this->symbolCount;
    }

    /**
     * @return array<string, NamespaceSymbol> Namespaces to rename, by name without a trailing `\`, longest first.
     *
     * @throws Exception
     */
    public function getNamespacesToRenameByName(): array
    {
        if (is_null($this->namespacesToRenameByName)) {
            $byName = [];
            /** @var NamespaceSymbol $symbol */
            foreach ($this->namespacesToRename as $symbol) {
                $name = rtrim($symbol->getOriginalFqdnName(), '\\');
                if (isset($byName[$name])) {
                    throw new Exception('losing data');
                }
                $byName[$name] = $symbol;
            }
            uksort($byName, fn($a, $b) => strlen((string) $b) - strlen((string) $a));
            $this->namespacesToRenameByName = $byName;
        }
        return $this->namespacesToRenameByName;
    }

    /**
     * A namespace is "active" when at least one symbol in it is being renamed.
     *
     * @return array<string, string> Original => replacement namespace name, without trailing `\`, longest first.
     */
    public function getActiveNamespaceReplacements(): array
    {
        if (is_null($this->activeNamespaceReplacements)) {
            $active = [];
            /** @var NamespacedSymbol $symbol */
            foreach ($this->namespacedToRenameNotGlobal->toArray() as $symbol) {
                $namespace = $symbol->getNamespace();
                $active[rtrim($namespace->getOriginalFqdnName(), '\\')] = rtrim($namespace->getReplacementFqdnName(), '\\');
            }
            uksort($active, fn($a, $b) => strlen((string) $b) - strlen((string) $a));
            $this->activeNamespaceReplacements = $active;
        }
        return $this->activeNamespaceReplacements;
    }

    public function getNamespacesToRename(): DiscoveredSymbols
    {
        return $this->namespacesToRename;
    }

    public function getNamespacedToRename(): DiscoveredSymbols
    {
        return $this->namespacedToRename;
    }

    public function getNamespacedToRenameNotGlobal(): DiscoveredSymbols
    {
        return $this->namespacedToRenameNotGlobal;
    }

    public function getGlobalClassesInterfacesTraitsToRename(): DiscoveredSymbols
    {
        return $this->globalClassesInterfacesTraitsToRename;
    }

    public function getFunctionsToRename(): DiscoveredSymbols
    {
        return $this->functionsToRename;
    }

    public function getConstantsToRename(): DiscoveredSymbols
    {
        return $this->constantsToRename;
    }

    public function getNamespacedToRenameByFqdn(string $fqdn): ?NamespacedSymbol
    {
        return $this->namespacedToRenameByFqdn[$fqdn] ?? null;
    }

    public function getFunctionToRenameByFqdn(string $fqdn): ?FunctionSymbol
    {
        return $this->functionsToRenameByFqdn[$fqdn] ?? null;
    }

    public function getGlobalClassInterfaceTraitToRenameByName(string $name): ?NamespacedSymbol
    {
        return $this->globalClassesInterfacesTraitsToRenameByName[$name] ?? null;
    }

    /**
     * The symbols which might be found in strings in which the given names are quoted.
     *
     * A symbol only matches the string pattern where its whole name follows a quote.
     *
     * @param array<string, true> $names Keyed by name: {@see self::getQuotedNames()}.
     *
     * @return DiscoveredSymbol[]
     */
    public function getStringSearchSymbolsForNames(array $names): array
    {
        $symbols = [];
        foreach (array_intersect_key($this->stringSearchSymbolsByName, $names) as $symbolsForName) {
            foreach ($symbolsForName as $symbol) {
                $symbols[spl_object_id($symbol)] = $symbol;
            }
        }
        foreach ($this->stringSearchSymbolsAlways as $symbol) {
            $symbols[spl_object_id($symbol)] = $symbol;
        }
        return array_values($symbols);
    }

    /**
     * The names which begin a quoted string in the text, e.g. `'Acme\\Lib\\Widget::$instance'` gives
     * `Acme\Lib\Widget`: the run of name characters after a quote and at most two backslashes, with doubled
     * (escaped) backslashes as single ones.
     *
     * The text need not be PHP: any quote character counts, so this finds at least every name that
     * {@see ChangePlanner::findSymbolPositionsInStrings()} can match.
     *
     * @return array<string, true> Keyed by name.
     */
    public static function getQuotedNames(string $text): array
    {
        preg_match_all(
            '/[\'"]\\\\{0,2}((?:[a-zA-Z0-9_\x7f-\xff]+\\\\{1,2})*[a-zA-Z0-9_\x7f-\xff]+)/',
            $text,
            $matches
        );

        $names = [];
        foreach ($matches[1] as $name) {
            $names[str_replace('\\\\', '\\', $name)] = true;
        }

        return $names;
    }

    /**
     * Every qualified-name-shaped candidate in the text: for each run of identifier characters and backslashes,
     * every segment-aligned substring, e.g. `\Foo\Bar\Baz` gives `Foo`, `Foo\Bar`, `Foo\Bar\Baz`, `Bar`,
     * `Bar\Baz`, `Baz`.
     *
     * @return array<string, true> Keyed by candidate name.
     */
    public static function getQualifiedNameCandidates(string $text): array
    {
        preg_match_all('/[a-zA-Z0-9_\x7f-\xff\\\\]+/', $text, $matches);

        $candidates = [];
        foreach (array_unique($matches[0]) as $run) {
            $segments = array_values(array_filter(explode('\\', $run), fn(string $segment): bool => '' !== $segment));
            $count = count($segments);
            for ($start = 0; $start < $count; $start++) {
                $name = $segments[$start];
                $candidates[$name] = true;
                for ($end = $start + 1; $end < $count; $end++) {
                    $name .= '\\' . $segments[$end];
                    $candidates[$name] = true;
                }
            }
        }

        return $candidates;
    }
}
