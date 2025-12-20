<?php
/**
 * @see \BrianHenryIE\Strauss\Pipeline\FileSymbolScanner
 */

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Files\File;
use InvalidArgumentException;

class DiscoveredSymbols
{
    /**
     * All discovered symbols, grouped by type, indexed by original name.
     *
     * @var array{T_NAMESPACE:array<string,NamespaceSymbol>, T_CONST:array<string,ConstantSymbol>, T_CLASS:array<string,ClassSymbol>, T_FUNCTION:array<string,FunctionSymbol>, T_TRAIT:array<string,TraitSymbol>, T_INTERFACE:array<string,InterfaceSymbol>}
     */
    protected array $types = [
        T_CLASS => [],
        T_CONST => [],
        T_NAMESPACE => [],
        T_FUNCTION => [],
        T_TRAIT => [],
        T_INTERFACE => [],
    ];

    public function __construct()
    {
        // TODO: Should this have the root package?
        $this->types[T_NAMESPACE]['\\'] = new NamespaceSymbol('\\', new File('', ''));
    }

    /**
     * @param DiscoveredSymbol $symbol
     */
    public function add(DiscoveredSymbol $symbol): void
    {
        switch (get_class($symbol)) {
            case NamespaceSymbol::class:
                $type = T_NAMESPACE;
                break;
            case ConstantSymbol::class:
                $type = T_CONST;
                break;
            case ClassSymbol::class:
                $type = T_CLASS;
                break;
            case FunctionSymbol::class:
                $type = T_FUNCTION;
                break;
            case InterfaceSymbol::class:
                $type = T_INTERFACE;
                break;
            case TraitSymbol::class:
                $type = T_TRAIT;
                break;
            default:
                throw new InvalidArgumentException('Unknown symbol type: ' . get_class($symbol));
        }
        // TODO: This should merge the symbols instead of overwriting them.
        $this->types[$type][$symbol->getOriginalSymbol()] = $symbol;
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
        return $this->types[T_CONST];
    }

    /**
     * @return array<string, NamespaceSymbol>
     */
    public function getNamespaces(): array
    {
        return $this->types[T_NAMESPACE];
    }

    public function getNamespace(string $namespace): ?NamespaceSymbol
    {
        return $this->types[T_NAMESPACE][$namespace] ?? null;
    }

    /**
     * @return array<string, ClassSymbol>
     */
    public function getGlobalClasses(): array
    {
        return array_filter(
            $this->types[T_CLASS],
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
        return $this->types[T_CLASS];
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
        foreach ($this->getNamespaces() as $key => $value) {
            $discoveredNamespaceReplacements[ $value->getOriginalSymbol() ] = $value;
        }

        uksort($discoveredNamespaceReplacements, function ($a, $b) {
            return strlen($a) <=> strlen($b);
        });

        unset($discoveredNamespaceReplacements['\\']);

        return $discoveredNamespaceReplacements;
    }

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
        $discoveredConstants = $this->getConstants();
        $discoveredConstants = array_filter(
            array_keys($discoveredConstants),
            function (string $replacement) use ($constantsPrefix) {
                return empty($constantsPrefix) || ! str_starts_with($replacement, $constantsPrefix);
            }
        );

        return $discoveredConstants;
    }

//  public function getDiscoveredConstantChanges(?string $functionsPrefix = ''): array
//  {
//      return array_filter(
//          $this->getDiscoveredConstants($functionsPrefix),
//          fn( $discoveredConstant ) => $discoveredConstant->isDoRename()
//      );
//  }

    /**
     * @return FunctionSymbol[]
     */
    public function getDiscoveredFunctions()
    {
        return $this->types[T_FUNCTION];
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

    public function getAll(): array
    {
        return array_merge(...array_values($this->types));
    }

    public function getDiscoveredTraits(): array
    {
        return (array) $this->types[T_TRAIT];
    }

    public function getDiscoveredInterfaces(): array
    {
        return (array) $this->types[T_INTERFACE];
    }

    /**
     * Get all discovered symbols that are classes, interfaces, or traits, i.e. only those that are autoloadable.
     *
     * @return array<DiscoveredSymbol>
     */
    public function getClassmapSymbols(): array
    {
        return array_merge(
            $this->getDiscoveredClasses(),
            $this->getDiscoveredInterfaces(),
            $this->getDiscoveredTraits(),
        );
    }

    public function getNamespaceSymbolByString(string $namespace): ?NamespaceSymbol
    {
        return $this->types[T_NAMESPACE][$namespace] ?? null;
    }
}
