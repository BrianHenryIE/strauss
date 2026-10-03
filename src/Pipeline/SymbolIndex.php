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

class SymbolIndex
{
    protected DiscoveredSymbols $discoveredSymbols;

    protected DiscoveredSymbols $namespacesToRename;

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
     * The symbols searched for in strings, by the first segment of their name.
     *
     * @var array<string, DiscoveredSymbol[]>
     */
    protected array $stringSearchSymbolsByFirstSegment = [];

    public function __construct(DiscoveredSymbols $discoveredSymbols)
    {
        $this->discoveredSymbols = $discoveredSymbols;

        $this->namespacesToRename = $discoveredSymbols->getNamespaces()->getToRename();
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

        /** @var DiscoveredSymbol $symbol */
        foreach (array_merge($this->namespacesToRename->toArray(), $this->namespacedToRename->toArray()) as $symbol) {
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
            $firstSegment = strstr($name, '\\', true) ?: $name;
            $this->stringSearchSymbolsByFirstSegment[$firstSegment][] = $symbol;
        }
    }

    public function isFor(DiscoveredSymbols $discoveredSymbols): bool
    {
        return $this->discoveredSymbols === $discoveredSymbols;
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
     * The symbols which might appear in a string containing the given words.
     *
     * A symbol can only match the string pattern if the first segment of its name is a whole word in the text.
     *
     * @param array<string, true> $words Keyed by word.
     *
     * @return DiscoveredSymbol[]
     */
    public function getStringSearchSymbolsForWords(array $words): array
    {
        $symbols = [];
        foreach (array_intersect_key($this->stringSearchSymbolsByFirstSegment, $words) as $symbolsForWord) {
            foreach ($symbolsForWord as $symbol) {
                $symbols[spl_object_id($symbol)] = $symbol;
            }
        }
        return array_values($symbols);
    }

    /**
     * The identifier-like words in the text: what a symbol name's first segment would have to be.
     *
     * @return array<string, true> Keyed by word.
     */
    public static function getWords(string $text): array
    {
        preg_match_all('/[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*/', $text, $matches);
        return array_fill_keys($matches[0], true);
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
