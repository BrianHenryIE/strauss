<?php
/**
 * A collection of `ComposerPackage`.
 */

namespace BrianHenryIE\Strauss\Composer;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;

/**
 * @implements IteratorAggregate<string, ComposerPackage>
 * @implements ArrayAccess<string, ComposerPackage>
 */
class DependenciesCollection implements IteratorAggregate, ArrayAccess, Countable
{

    /**
     * @var array<string,ComposerPackage>
     */
    protected array $dependencies = [];

    /**
     * @param array<ComposerPackage> $dependencies
     */
    public function __construct(
        array $dependencies
    ) {
        foreach ($dependencies as $dependency) {
            $this->dependencies[$dependency->getPackageName()] = $dependency;
        }
    }

    /**
     * @return array<string,ComposerPackage>
     */
    public function toArray()
    {
        return $this->dependencies;
    }

    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        return new ArrayIterator($this->dependencies);
    }

    /**
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return (bool) $this->offsetGet($offset);
    }

    /**
     * @return ?ComposerPackage
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->dependencies[$offset] ?? null;
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        throw new \BadMethodCallException();
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        throw new \BadMethodCallException();
    }

    /**
     * TODO: direct dependencies or complete dependency tree?
     *
     * @return int
     */
    #[\ReturnTypeWillChange]
    public function count()
    {
        return count($this->dependencies);
    }

    public function add(ComposerPackage $package): void
    {
        $this->dependencies[$package->getPackageName()] = $package;
    }
}
