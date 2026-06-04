<?php
/**
 * @see \BrianHenryIE\Strauss\Pipeline\FileSymbolScanner
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Types;

use ArrayAccess;
use ArrayIterator;
use BadMethodCallException;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use ReturnTypeWillChange;
use Traversable;

/**
 * @implements IteratorAggregate<string, DiscoveredSymbol>
 * @implements ArrayAccess<string, DiscoveredSymbol>
 */
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
     * @var array{'NAMESPACE':array<string,NamespaceSymbol>, 'CONST':array<string,ConstantSymbol>, 'CLASS':array<string,ClassSymbol>, 'FUNCTION':array<string,FunctionSymbol>, 'TRAIT':array<string,TraitSymbol>, 'INTERFACE':array<string,InterfaceSymbol>, 'ENUM':array<string,NamespacedSymbol>}
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
        // get_class() is intentional: instanceof would match subclasses (e.g. Psr0NamespaceSymbol instanceof NamespaceSymbol),
        // routing them to the wrong bucket. Each concrete type must be listed explicitly.
        switch (get_class($symbol)) {
            case NamespaceSymbol::class:
                // Fall-through.
            case Psr0NamespaceSymbol::class:
                $this->types[self::NAMESPACE_SYMBOL][$symbol->getOriginalFqdnName()] = $symbol;
                return;
            case ConstantSymbol::class:
                $this->types[self::CONST_SYMBOL][$symbol->getOriginalFqdnName()] = $symbol;
                return;
            case ClassSymbol::class:
                $this->types[self::CLASS_SYMBOL][$symbol->getOriginalFqdnName()] = $symbol;
                return;
            case FunctionSymbol::class:
                $this->types[self::FUNCTION_SYMBOL][$symbol->getOriginalFqdnName()] = $symbol;
                return;
            case InterfaceSymbol::class:
                $this->types[self::INTERFACE_SYMBOL][$symbol->getOriginalFqdnName()] = $symbol;
                return;
            case TraitSymbol::class:
                $this->types[self::TRAIT_SYMBOL][$symbol->getOriginalFqdnName()] = $symbol;
                return;
            default:
                throw new InvalidArgumentException('Unknown symbol type: ' . get_class($symbol));
        }
    }


    public function has(DiscoveredSymbol $symbol): bool
    {
        switch (get_class($symbol)) {
            case NamespaceSymbol::class:
                return isset($this->types[self::NAMESPACE_SYMBOL][$symbol->getOriginalFqdnName()]);
            case ConstantSymbol::class:
                return isset($this->types[self::CONST_SYMBOL][$symbol->getOriginalFqdnName()]);
            case ClassSymbol::class:
                return isset($this->types[self::CLASS_SYMBOL][$symbol->getOriginalFqdnName()]);
            case FunctionSymbol::class:
                return isset($this->types[self::FUNCTION_SYMBOL][$symbol->getOriginalFqdnName()]);
            case InterfaceSymbol::class:
                return isset($this->types[self::INTERFACE_SYMBOL][$symbol->getOriginalFqdnName()]);
            case TraitSymbol::class:
                return isset($this->types[self::TRAIT_SYMBOL][$symbol->getOriginalFqdnName()]);
            default:
                throw new InvalidArgumentException('Unknown symbol type: ' . get_class($symbol));
        }
    }

    public function get(string $fqdnName): ?DiscoveredSymbol
    {
        $found = array_reduce(
            $this->types,
            fn (array $carry, array $symbol) => isset($symbol[$fqdnName]) ? array_merge($carry, [$symbol[$fqdnName]]) : $carry,
            []
        );

        if (count($found) === 0) {
            return null;
        }

        if (count($found) > 1) {
            $names = array_map(
                fn (DiscoveredSymbol $symbol):string => $symbol->getOriginalLocalName(),
                $found
            );
            // E.g. an interface and class have the same name.
            throw new \Exception('multiple symbols with the same name: ' . implode(', ', $names));
        }

        return $this->getClass($fqdnName)
            ?? $this->getInterface($fqdnName)
            ?? $this->getTrait($fqdnName)
            ?? $this->getEnum($fqdnName)
            ?? $this->getFunction($fqdnName)
            ?? $this->getConst($fqdnName)
            ?? $this->getNamespace($fqdnName)
            ?? $this->getNamespaceSymbolByString($fqdnName);
    }

    public function getSymbols(): DiscoveredSymbols
    {
        return new DiscoveredSymbols(
            array_merge(
                array_values($this->getNamespaces()->toArray()),
                array_values($this->getNamespacedSymbols()->toArray()),
                array_values($this->getConstants()->toArray()),
                array_values($this->getDiscoveredFunctions()->toArray()),
            )
        );
    }

    public function getConstants(): DiscoveredSymbols
    {
        return new DiscoveredSymbols($this->types[self::CONST_SYMBOL]);
    }

    public function getNamespaces(): DiscoveredSymbols
    {
        return new DiscoveredSymbols($this->types[self::NAMESPACE_SYMBOL]);
    }

    public function getNamespace(string $namespace): ?NamespaceSymbol
    {
        return $this->types[self::NAMESPACE_SYMBOL][trim($namespace, '\\')] ?? null;
    }

    /**
     * Get all symbols that may have a namespace (i.e. no classes, interfaces etc.).
     */
    public function getNamespacedSymbols(): DiscoveredSymbols
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

    public function getGlobalClassesInterfacesTraits(): DiscoveredSymbols
    {
        return new DiscoveredSymbols(
            array_filter(
                $this->getNamespacedSymbols()->toArray(),
                fn($symbol) => ($symbol instanceof NamespacedSymbol) && $symbol->getNamespace()->isGlobal()
            )
        );
    }

    public function getGlobalClassesInterfacesTraitsToRename(): DiscoveredSymbols
    {
        return new DiscoveredSymbols(
            array_filter(
                $this->getGlobalClassesInterfacesTraits()->toArray(),
                fn($classSymbol) => $classSymbol->isDoRename()
            )
        );
    }

    public function getAllClasses(): DiscoveredSymbols
    {
        return new DiscoveredSymbols($this->types[self::CLASS_SYMBOL]);
    }

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
     * @deprecated Use ::getConstants()
     */
    public function getDiscoveredConstants(): DiscoveredSymbols
    {
        return $this->getConstants();
    }

    public function getDiscoveredFunctions(): DiscoveredSymbols
    {
        return new DiscoveredSymbols($this->types[self::FUNCTION_SYMBOL]);
    }

    public function getDiscoveredTraits(): DiscoveredSymbols
    {
        return new DiscoveredSymbols($this->types[self::TRAIT_SYMBOL]);
    }

    public function getDiscoveredInterfaces(): DiscoveredSymbols
    {
        return new DiscoveredSymbols($this->types[self::INTERFACE_SYMBOL]);
    }

    public function getToRename(): DiscoveredSymbols
    {
        return new DiscoveredSymbols(
            array_filter(
                $this->toArray(),
                fn(DiscoveredSymbol $symbol) => $symbol->isDoRename() && $symbol->getOriginalFqdnName() !== $symbol->getReplacementFqdnName()
            )
        );
    }

    public function getNamespaceSymbolByString(string $namespace): ?NamespaceSymbol
    {
        return $this->types[self::NAMESPACE_SYMBOL][$namespace] ?? null;
    }

    public function getClass(string $fullyQualifiedClassname): ?ClassSymbol
    {
        return $this->types[self::CLASS_SYMBOL][$fullyQualifiedClassname] ?? null;
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
    public function getEnum(string $enumName): ?NamespacedSymbol
    {
        return $this->types[self::ENUM_SYMBOL][$enumName] ?? null;
    }

    /**
     * @return array<DiscoveredSymbol>
     */
    public function toArray(): array
    {
        // TODO: Can this lose data with common array keys? Check does the count of the sum of all arrays still equal the count of what is being returned.
        return array_merge(...array_values($this->types));
    }

    /**
     * @return string[]
     */
    public function originalLocalNames(): array
    {
        return array_map(
            fn(DiscoveredSymbol $symbol) => $symbol->getOriginalLocalName(),
            $this->toArray()
        );
    }

    /**
     * @return DiscoveredSymbol[]
     */
    public function __serialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return Traversable<DiscoveredSymbol>
     */
    #[ReturnTypeWillChange]
    public function getIterator()
    {
        return new ArrayIterator($this->toArray());
    }

    /**
     * @param DiscoveredSymbol $offset
     *
     * @return bool
     */
    #[ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        /**
         * TODO: use spl array and accept DiscoveredSymbol as the array key.
         *
         * @see https://stackoverflow.com/questions/4642980/can-i-use-an-instantiated-object-as-an-array-key
         */
        // Fixing this breaks tests.
        return in_array($offset, $this->toArray(), true);
        // return array_key_exists($offset, $this->toArray());
    }

    /**
     * @param string $offset
     *
     * @return DiscoveredSymbol|null
     */
    #[ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->toArray()[$offset] ?? null;
    }

    /**
     * @param string $offset
     * @param DiscoveredSymbol$value
     *
     * @return void
     */
    #[ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        throw new BadMethodCallException();
    }

    /**
     * @param string $offset
     *
     * @return void
     */
    #[ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        throw new BadMethodCallException();
    }

    /**
     * So `count( $discoveredSymbols )` will work.
     *
     * @return int
     */
    #[ReturnTypeWillChange]
    public function count()
    {
        return array_reduce(
            $this->types,
            fn(int $carry, array $item) => $carry + count($item),
            0
        );
    }

    public function notGlobal(): self
    {
        $all = [];
        /**
         * @var string $type
         * @var array<string, DiscoveredSymbol> $types
         */
        foreach ($this->types as $type => $types) {
            /**
             * @var string $index
             * @var DiscoveredSymbol $symbol
             */
            foreach ($types as $symbol) {
                if (!$symbol->isGlobal()) {
                    $all[] = $symbol;
                }
            }
        }
        return new self($all);
    }
}
