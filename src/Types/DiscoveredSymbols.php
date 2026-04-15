<?php
/**
 * @see \BrianHenryIE\Strauss\Pipeline\FileSymbolScanner
 */

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Files\File;
use InvalidArgumentException;

class DiscoveredSymbols
{
    private const CLASS_SYMBOL = 'CLASS';
    private const CONST_SYMBOL = 'CONST';
    private const NAMESPACE_SYMBOL = 'NAMESPACE';
    private const FUNCTION_SYMBOL = 'FUNCTION';
    private const TRAIT_SYMBOL = 'TRAIT';
    private const INTERFACE_SYMBOL = 'INTERFACE';

    /**
     * All discovered symbols, grouped by type, indexed by original name.
     *
     * @var array{'NAMESPACE':array<string,NamespaceSymbol>, 'CONST':array<string,ConstantSymbol>, 'CLASS':array<string,ClassSymbol>, 'FUNCTION':array<string,FunctionSymbol>, 'TRAIT':array<string,TraitSymbol>, 'INTERFACE':array<string,InterfaceSymbol>}
     */
    protected array $types = [
        self::CLASS_SYMBOL => [],
        self::CONST_SYMBOL => [],
        self::NAMESPACE_SYMBOL => [],
        self::FUNCTION_SYMBOL => [],
        self::TRAIT_SYMBOL => [],
        self::INTERFACE_SYMBOL => [],
    ];

    public function __construct()
    {
        // TODO: Should this have the root package?
        $this->types[self::NAMESPACE_SYMBOL]['\\'] = new NamespaceSymbol('\\', new File('', ''));
    }

    /**
     * TODO: This should merge the symbols instead of overwriting them.
     *
     * @param DiscoveredSymbol $symbol
     */
    public function add(DiscoveredSymbol $symbol): void
    {
        switch (get_class($symbol)) {
            case NamespaceSymbol::class:
                $this->types[self::NAMESPACE_SYMBOL][$symbol->getOriginalSymbol()] = $symbol;
                return;
            case ConstantSymbol::class:
                $this->types[self::CONST_SYMBOL][$symbol->getOriginalSymbol()] = $symbol;
                return;
            case ClassSymbol::class:
                $this->types[self::CLASS_SYMBOL][$symbol->getOriginalSymbol()] = $symbol;
                return;
            case FunctionSymbol::class:
                $this->types[self::FUNCTION_SYMBOL][$symbol->getOriginalSymbol()] = $symbol;
                return;
            case InterfaceSymbol::class:
                $this->types[self::INTERFACE_SYMBOL][$symbol->getOriginalSymbol()] = $symbol;
                return;
            case TraitSymbol::class:
                $this->types[self::TRAIT_SYMBOL][$symbol->getOriginalSymbol()] = $symbol;
                return;
            default:
                throw new InvalidArgumentException('Unknown symbol type: ' . get_class($symbol));
        }
    }

    /**
     * @return DiscoveredSymbol[]
     */
    public function getSymbols(): array
    {
        return array_merge(
            array_values($this->getNamespaces()),
            array_values($this->getGlobalClasses()),
            array_values($this->getConstants()),
            array_values($this->getDiscoveredFunctions()),
        );
    }

    /**
     * @return array<string, ConstantSymbol>
     */
    public function getConstants(): array
    {
        return $this->types[self::CONST_SYMBOL];
    }

    /**
     * @return array<string, NamespaceSymbol>
     */
    public function getNamespaces(): array
    {
        return $this->types[self::NAMESPACE_SYMBOL];
    }

    public function getNamespace(string $namespace): ?NamespaceSymbol
    {
        return $this->types[self::NAMESPACE_SYMBOL][$namespace] ?? null;
    }

    /**
     * @return array<string, ClassSymbol>
     */
    public function getGlobalClasses(): array
    {
        return array_filter(
            $this->types[self::CLASS_SYMBOL],
            fn($classSymbol) => '\\' === $classSymbol->getNamespace()
        );
    }

    /**
     * @return array<string, ClassSymbol>
     */
    public function getGlobalClassChanges(): array
    {
        return array_filter(
            $this->getGlobalClasses(),
            fn($classSymbol) => $classSymbol->isDoRename()
        );
    }

    /**
     * @return array<string, ClassSymbol>
     */
    public function getAllClasses(): array
    {
        return $this->types[self::CLASS_SYMBOL];
    }

    /**
     * TODO: Order by longest string first. (or instead, record classnames with their namespaces)
     *
     * @return array<string, NamespaceSymbol>
     */
    public function getDiscoveredNamespaces(?string $namespacePrefix = ''): array
    {
        $discoveredNamespaceReplacements = [];

        // When running subsequent times, try to discover the original namespaces.
        // This is naive: it will not work where namespace replacement patterns have been used.
        foreach ($this->getNamespaces() as $namespaceSymbol) {
            $discoveredNamespaceReplacements[ $namespaceSymbol->getOriginalSymbol() ] = $namespaceSymbol;
        }

        uksort($discoveredNamespaceReplacements, function ($a, $b) {
            return strlen($a) <=> strlen($b);
        });

        unset($discoveredNamespaceReplacements['\\']);

        return $discoveredNamespaceReplacements;
    }

    /**
     * @return array<string, NamespaceSymbol>
     */
    public function getDiscoveredNamespaceChanges(?string $namespacePrefix = ''): array
    {
        return array_filter(
            $this->getdiscoveredNamespaces($namespacePrefix),
            fn($namespaceSymbol) => $namespaceSymbol->isDoRename()
        );
    }

    /**
     * @return string[]
     */
    public function getDiscoveredClasses(?string $classmapPrefix = ''): array
    {
        $discoveredClasses = $this->getGlobalClasses();

        return array_filter(
            array_keys($discoveredClasses),
            function (string $replacement) use ($classmapPrefix) {
                return empty($classmapPrefix) || ! str_starts_with($replacement, $classmapPrefix);
            }
        );
    }

    /**
     * @return string[]
     */
    public function getDiscoveredConstants(?string $constantsPrefix = ''): array
    {
        return array_filter(
            array_keys($this->getConstants()),
            function (string $replacement) use ($constantsPrefix) {
                return empty($constantsPrefix) || ! str_starts_with($replacement, $constantsPrefix);
            }
        );
    }

    /**
     * Constant names that should be prefixed (symbol has isDoRename()).
     *
     * @return string[]
     */
    public function getDiscoveredConstantChanges(?string $constantsPrefix = ''): array
    {
        $constantsToRename = array_filter(
            $this->getConstants(),
            fn(ConstantSymbol $symbol) => $symbol->isDoRename()
        );
        return array_filter(
            array_keys($constantsToRename),
            function (string $replacement) use ($constantsPrefix) {
                return empty($constantsPrefix) || ! str_starts_with($replacement, $constantsPrefix);
            }
        );
    }

    /**
     * @return FunctionSymbol[]
     */
    public function getDiscoveredFunctions(): array
    {
        return $this->types[self::FUNCTION_SYMBOL];
    }

    /**
     * @return FunctionSymbol[]
     */
    public function getDiscoveredFunctionChanges(): array
    {
        return array_filter(
            $this->getDiscoveredFunctions(),
            fn($discoveredFunction) => $discoveredFunction->isDoRename()
        );
    }

    /**
     * @return array<string,DiscoveredSymbol>
     */
    public function getAll(): array
    {
        return array_merge(...array_values($this->types));
    }

    /**
     * @return array<string,TraitSymbol>
     */
    public function getDiscoveredTraits(): array
    {
        return (array) $this->types[self::TRAIT_SYMBOL];
    }

    /**
     * @return array<string,InterfaceSymbol>
     */
    public function getDiscoveredInterfaces(): array
    {
        return (array) $this->types[self::INTERFACE_SYMBOL];
    }

    /**
     * Get all discovered symbols that are classes, interfaces, or traits, i.e. only those that are autoloadable.
     *
     * @return array<DiscoveredSymbol>
     */
    public function getClassmapSymbols(): array
    {
        return array_merge(
            $this->getGlobalClasses(),
            $this->getDiscoveredInterfaces(),
            $this->getDiscoveredTraits(),
        );
    }

    public function getNamespaceSymbolByString(string $namespace): ?NamespaceSymbol
    {
        return $this->types[self::NAMESPACE_SYMBOL][$namespace] ?? null;
    }
}
