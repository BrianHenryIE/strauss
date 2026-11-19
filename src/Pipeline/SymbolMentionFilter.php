<?php

/**
 * A cheap text search to decide whether a file could use any of the discovered symbols, before parsing it.
 *
 * When prefixing in place, the files of every other installed package may use the prefixed symbols, but most do not,
 * and parsing them all (e.g. all of Laravel and Symfony when only `psr/log` is prefixed) exhausts memory. Some of them
 * are not PHP at all, e.g. `*.blade.php` templates.
 *
 * @package brianhenryie/strauss
 * @see     https://github.com/BrianHenryIE/strauss/issues/343
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;

/**
 * Searches for the first part of each discovered namespace, e.g. `Psr` for `Psr\Log`, and the name of each discovered
 * global class, interface, trait, enum, function and constant, as whole words, ignoring case.
 *
 * Errs towards a match: the first namespace part also finds group `use` statements, e.g. `use Psr\{Log\...}`, and
 * names in strings, e.g. `'Psr\\Log\\...'`. A file with no match cannot use any of the symbols, so needs no changes.
 */
class SymbolMentionFilter
{
    /**
     * The number of names in each regular expression, to keep each within PCRE's limits.
     */
    protected const NAMES_PER_PATTERN = 250;

    protected DiscoveredSymbols $discoveredSymbols;

    /**
     * @var ?string[] Built on first use.
     */
    protected ?array $patterns = null;

    /**
     * @param DiscoveredSymbols $discoveredSymbols The symbols a file might use, e.g. those found in the packages to
     *                                             prefix.
     */
    public function __construct(DiscoveredSymbols $discoveredSymbols)
    {
        $this->discoveredSymbols = $discoveredSymbols;
    }

    /**
     * @return string[] The names searched for, sorted.
     */
    public function getNames(): array
    {
        $names = [];

        foreach ($this->discoveredSymbols->getNamespaces()->toArray() as $namespace) {
            if ($namespace instanceof NamespaceSymbol && !$namespace->isGlobal()) {
                $names[] = explode('\\', trim($namespace->getOriginalFqdnName(), '\\'))[0];
            }
        }

        // Every symbol in the global namespace, despite the name.
        foreach ($this->discoveredSymbols->getGlobalClassesInterfacesTraits()->toArray() as $symbol) {
            $names[] = $symbol->getOriginalLocalName();
        }

        $names = array_values(array_unique(array_filter($names, fn(string $name): bool => '' !== $name)));
        sort($names);

        return $names;
    }

    /**
     * @param string $contents A file's contents.
     */
    public function isMentionedIn(string $contents): bool
    {
        foreach ($this->getPatterns() as $pattern) {
            if (1 === preg_match($pattern, $contents)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    protected function getPatterns(): array
    {
        if (is_null($this->patterns)) {
            $this->patterns = array_map(
                fn(array $names): string => '/\b(?:'
                    . implode('|', array_map(fn(string $name): string => preg_quote($name, '/'), $names))
                    . ')\b/i',
                array_chunk($this->getNames(), self::NAMES_PER_PATTERN)
            );
        }

        return $this->patterns;
    }
}
