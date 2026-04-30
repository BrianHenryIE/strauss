<?php

namespace BrianHenryIE\Strauss\Files;

use ArrayAccess;
use ArrayIterator;
use BadMethodCallException;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<string, FileBase>
 * @implements ArrayAccess<string, FileBase>
 */
class DiscoveredFiles implements IteratorAggregate, ArrayAccess, Countable
{
    /** @var array<string,FileBase|File|FileWithDependency> */
    protected array $files = [];

    /**
     * @param FileBase[] $files
     */
    public function __construct(array $files = [])
    {
        $this->files = $files;
    }

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

    /**
     * @return Traversable
     */
    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        return new ArrayIterator($this->files);
    }

    /**
     * @param string $offset Absolute path.
     *
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return in_array($offset, $this->files, true);
    }

    /**
     * @param string $offset Absolute path.
     *
     * @return ?FileBase
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->files[$offset] ?? null;
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        throw new BadMethodCallException();
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        throw new BadMethodCallException();
    }

    /**
     * @return int
     */
    #[\ReturnTypeWillChange]
    public function count()
    {
        return $this->files;
    }

    /**
     * @return FileWithDependency[]
     */
    public function getPsr0(): array
    {
        return array_filter(
            $this->files,
            fn(FileBase $file) => $file instanceof FileWithDependency && $file->isPsr0()
        );
    }
}
