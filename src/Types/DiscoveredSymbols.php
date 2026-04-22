<?php
/**
 * @see \BrianHenryIE\Strauss\Pipeline\FileSymbolScanner
 */

namespace BrianHenryIE\Strauss\Types;

use ArrayAccess;
use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;

class DiscoveredSymbols implements IteratorAggregate, ArrayAccess, Countable
{
    private const CLASS_SYMBOL = 'CLASS';
    private const CONST_SYMBOL = 'CONST';
    private const NAMESPACE_SYMBOL = 'NAMESPACE';
    private const FUNCTION_SYMBOL = 'FUNCTION';
    private const TRAIT_SYMBOL = 'TRAIT';
    private const INTERFACE_SYMBOL = 'INTERFACE';
    private const ENUM_SYMBOL = 'ENUM';

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
        self::ENUM_SYMBOL => [],
    ];

    /**
     * @param DiscoveredSymbol[] $symbols
     */
    public function __construct(array $symbols = [])
    {
        if (empty($symbols)) {
            $this->types[self::NAMESPACE_SYMBOL]['\\'] = NamespaceSymbol::global();
        }
        foreach ($symbols as $symbol) {
            $this->add($symbol);
        }
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
    public function getSymbols(): DiscoveredSymbols
    {
        return new DiscoveredSymbols(
            array_merge(
                array_values($this->getNamespaces()->toArray()),
                array_values($this->getGlobalClassesInterfacesTraits()->toArray()),
                array_values($this->getConstants()->toArray()),
                array_values($this->getDiscoveredFunctions()->toArray()),
            )
        );
    }

    /**
     * @return array<string, ConstantSymbol>
     */
    public function getConstants(): DiscoveredSymbols
    {
        return new DiscoveredSymbols($this->types[self::CONST_SYMBOL]);
    }

    /**
     * @return array<string, NamespaceSymbol>
     */
    public function getNamespaces(): DiscoveredSymbols
    {
        return new DiscoveredSymbols($this->types[self::NAMESPACE_SYMBOL]);
    }

    public function getNamespace(string $namespace): ?NamespaceSymbol
    {
        return $this->types[self::NAMESPACE_SYMBOL][$namespace] ?? null;
    }

    /**
     * @return array<string, ClassSymbol>
     */
    public function getClassesInterfacesTraits(): DiscoveredSymbols
    {
        return new DiscoveredSymbols(
            array_merge(
                $this->types[self::CLASS_SYMBOL],
                $this->types[self::TRAIT_SYMBOL],
                $this->types[self::INTERFACE_SYMBOL],
                $this->types[self::ENUM_SYMBOL],
            )
        );
    }

    /**
     * @return array<string, ClassSymbol>
     */
    public function getGlobalClassesInterfacesTraits(): DiscoveredSymbols
    {
        return new DiscoveredSymbols(
            array_filter(
                $this->getClassesInterfacesTraits()->toArray(),
                fn($symbol) => ($symbol instanceof NamespacedSymbol) && $symbol->getNamespace()->isGlobal()
            )
        );
    }

    /**
     * @return array<string, ClassSymbol>
     */
    public function getGlobalClassesInterfacesTraitsToRename(): DiscoveredSymbols
    {
        return new DiscoveredSymbols(
            array_filter(
                $this->getGlobalClassesInterfacesTraits()->toArray(),
                fn($classSymbol) => $classSymbol->isDoRename()
            )
        );
    }

    /**
     * @return array<string, ClassSymbol>
     */
    public function getAllClasses(): DiscoveredSymbols
    {
        return new DiscoveredSymbols($this->types[self::CLASS_SYMBOL]);
    }

    /**
     * TODO: Order by longest string first. (or instead, record classnames with their namespaces)
     *
     * @return array<string, NamespaceSymbol>
     */
    public function getDiscoveredNamespaces(): DiscoveredSymbols
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

        // TODO: should this stay, since it should have a list of relevant files in it.
        unset($discoveredNamespaceReplacements['\\']);

        return new DiscoveredSymbols($discoveredNamespaceReplacements);
    }

    /**
     * @return string[]
     */
    public function getDiscoveredClasses(?string $classmapPrefix = ''): DiscoveredSymbols
    {
        $discoveredClasses = $this->getGlobalClassesInterfacesTraits()->toArray();

        return new DiscoveredSymbols(
            array_filter(
                $discoveredClasses,
                function ($replacement) use ($classmapPrefix) {
                    return empty($classmapPrefix) || ! str_starts_with($replacement->getLocalReplacement(), $classmapPrefix);
                }
            )
        );
    }

    /**
     * @return string[]
     */
    public function getDiscoveredConstants(?string $constantsPrefix = ''): DiscoveredSymbols
    {
        return new DiscoveredSymbols(
            array_filter(
                $this->getConstants()->toArray(),
                function (ConstantSymbol $replacement) use ($constantsPrefix) {
                    return empty($constantsPrefix) || ! str_starts_with($replacement->getOriginalSymbol(), $constantsPrefix);
                }
            )
        );
    }

    /**
     * Constant names that should be prefixed (symbol has isDoRename()).
     *
     * @return string[]
     */
    public function getDiscoveredConstantChanges(?string $constantsPrefix = ''): DiscoveredSymbols
    {
        /** @var ConstantSymbol[] $constantsToRename */
        $constantsToRename = array_filter(
            $this->getConstants()->toArray(),
            fn(DiscoveredSymbol $symbol): bool => ($symbol instanceof ConstantSymbol) && $symbol->isDoRename()
        );
        return new DiscoveredSymbols(
            array_filter(
                $constantsToRename,
                function (ConstantSymbol $replacement) use ($constantsPrefix) {
                    return empty($constantsPrefix) || ! str_starts_with($replacement->getLocalReplacement(), $constantsPrefix);
                }
            )
        );
    }

    /**
     * @return FunctionSymbol[]
     */
    public function getDiscoveredFunctions(): DiscoveredSymbols
    {
        return new DiscoveredSymbols($this->types[self::FUNCTION_SYMBOL]);
    }

    /**
     * @return FunctionSymbol[]
     */
    public function getDiscoveredFunctionChanges(): DiscoveredSymbols
    {
        return new DiscoveredSymbols(
            array_filter(
                $this->getDiscoveredFunctions()->toArray(),
                fn(DiscoveredSymbol $discoveredFunction) => ($discoveredFunction instanceof FunctionSymbol) && $discoveredFunction->isDoRename()
            )
        );
    }

    /**
     * @return array<string,TraitSymbol>
     */
    public function getDiscoveredTraits(): DiscoveredSymbols
    {
        return new DiscoveredSymbols($this->types[self::TRAIT_SYMBOL]);
    }

    /**
     * @return array<string,InterfaceSymbol>
     */
    public function getDiscoveredInterfaces(): DiscoveredSymbols
    {
        return new DiscoveredSymbols($this->types[self::INTERFACE_SYMBOL]);
    }

    /**
     * Get all discovered symbols that are classes, interfaces, or traits, i.e. only those that are autoloadable.
     *
     * @return array<DiscoveredSymbol>
     */
    public function getClassmapSymbols(): DiscoveredSymbols
    {
        return new DiscoveredSymbols(
            array_merge(
                $this->getGlobalClassesInterfacesTraits(),
                $this->getDiscoveredInterfaces(),
                $this->getDiscoveredTraits(),
            )
        );
    }

    public function getToRename(): DiscoveredSymbols
    {
        return new DiscoveredSymbols(
            array_filter(
                $this->toArray(),
                fn(DiscoveredSymbol $symbol) =>
                    ($symbol instanceof NamespacedSymbol && !$symbol->getNamespace()->isGlobal() && $symbol->getNamespace()->isDoRename())
                    || ($symbol->isDoRename() && $symbol->getOriginalSymbol() !== $symbol->getReplacementFqdnName())
            )
        );
    }

    public function getNamespaceSymbolByString(string $namespace): ?NamespaceSymbol
    {
        return $this->types[self::NAMESPACE_SYMBOL][$namespace] ?? null;
    }

    public function getClass(string $class): ?ClassSymbol
    {
        return $this->types[self::CLASS_SYMBOL][$class] ?? null;
    }
    public function getConst(string $const): ?ConstantSymbol
    {
        return $this->types[self::CONST_SYMBOL][$const] ?? null;
    }
    public function getFunction(string $function): ?FunctionSymbol
    {
        return $this->types[self::FUNCTION_SYMBOL][$function] ?? null;
    }
    public function getTrait(string $trait): ?TraitSymbol
    {
        return $this->types[self::TRAIT_SYMBOL][$trait] ?? null;
    }
    public function getInterface(string $interface): ?InterfaceSymbol
    {
        return $this->types[self::INTERFACE_SYMBOL][$interface] ?? null;
    }
    public function getEnum(string $enumName): ?TraitSymbol
    {
        return $this->types[self::ENUM_SYMBOL][$enumName] ?? null;
    }

    /**
     * @return array<DiscoveredSymbol>
     */
    public function toArray(): array
    {
        unset($this->types[self::NAMESPACE_SYMBOL]['\\']);
        return array_merge(...array_values($this->types));
    }

    public function __serialize(): array
    {
        return $this->toArray();
    }

    public function getIterator()
    {
        return new ArrayIterator($this->toArray());
    }

    public function offsetExists($offset)
    {
        return in_array($offset, $this->toArray(), true);
    }

    public function offsetGet($offset)
    {
        return $this->toArray()[$offset] ?? null;
    }

    public function offsetSet($offset, $value)
    {
        throw new \BadMethodCallException();
    }

    public function offsetUnset($offset)
    {
        throw new \BadMethodCallException();
    }

    /**
     * So `count( $discoveredSymbols )` will work.
     */
    public function count()
    {
        return array_reduce(
            $this->types,
            fn(int $count, array $item) => $count += count($item),
            0
        );
    }
}
