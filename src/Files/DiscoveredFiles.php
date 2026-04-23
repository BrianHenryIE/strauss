<?php

namespace BrianHenryIE\Strauss\Files;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;

class DiscoveredFiles implements IteratorAggregate, ArrayAccess, Countable
{
    /** @var array<string,FileBase|File|FileWithDependency> */
    protected array $files = [];

    public function add(FileBase $file): void
    {
        $this->files[$file->getSourcePath()] = $file;
    }

    /**
     * @return array<string,FileBase|File|FileWithDependency>
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * Fetch/check if a file exists in the discovered files.
     *
     * @param string $sourceAbsolutePath Full path to the file.
     */
    public function getFile(string $sourceAbsolutePath): ?FileBase
    {
        return $this->files[$sourceAbsolutePath] ?? null;
    }

    public function sort(): void
    {
        ksort($this->files);
    }

    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        return new ArrayIterator($this->files);
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return in_array($offset, $this->files, true);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->files[$offset] ?? null;
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
     * So `count( $discoveredSymbols )` will work.
     */
    #[\ReturnTypeWillChange]
    public function count()
    {
        return $this->files;
    }
}
