<?php
/**
 * When running with `--dry-run` the filesystem should be read-only.
 *
 * This should work with read operations working as normal but write operations should be
 * cached so they appear to have been successful but are not actually written to disk.
 */

namespace BrianHenryIE\Strauss\Helpers;

use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToReadFile;

class ReadOnlyFileSystem implements FilesystemOperator
{
    protected FilesystemOperator $filesystem;

    protected array $files = [];

    protected array $deleted = [];

    public function __construct(FilesystemOperator $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    public function fileExists(string $location): bool
    {
        return !isset($this->deleted[$location]) &&
               (isset($this->files[$location]) || $this->filesystem->fileExists($location));
    }

    public function write(string $location, string $contents, array $config = []): void
    {
        $this->files[$location] = $contents;
        unset($this->deleted[$location]);
    }

    public function writeStream(string $location, $contents, array $config = []): void
    {
        throw new \BadMethodCallException('Not yet implemented');
    }

    public function read(string $location): string
    {
        if (isset($this->deleted[$location])) {
            throw UnableToReadFile::fromLocation($location);
        }
        return $this->files[ $location ] ?? $this->filesystem->read($location);
    }

    public function readStream(string $location)
    {
        throw new \BadMethodCallException('Not yet implemented');
    }

    public function delete(string $location): void
    {
        unset($this->files[$location]);
        $this->deleted[$location] = true;
    }

    public function deleteDirectory(string $location): void
    {
        unset($this->files[$location]);
        $this->deleted[$location] = true;
    }


    public function createDirectory(string $location, array $config = []): void
    {
        $this->files[$location] = true;
        unset($this->deleted[$location]);
    }

    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        /** @var FileAttributes[] $actual */
        $actual = $this->filesystem->listContents($location, $deep)->toArray();

        $toAdd = array_filter($this->files, function ($key) use ($location) {
            return strpos($key, $location) === 0;
        }, ARRAY_FILTER_USE_KEY);

        foreach ($toAdd as $path => $contents) {
            $actual[] = new FileAttributes($path, strlen($contents), 'public');
        }

        $afterFilterDeleted = array_filter(
            $actual,
            fn($item) => ! in_array('/'.$item->path(), array_keys($this->deleted))
        );

        return new DirectoryListing($afterFilterDeleted);
    }

    public function move(string $source, string $destination, array $config = []): void
    {
        throw new \BadMethodCallException('Not yet implemented');
    }

    public function copy(string $source, string $destination, array $config = []): void
    {
        $this->files[$destination] = $this->read($source);
        unset($this->deleted[$destination]);
    }

    public function lastModified(string $path): int
    {
        throw new \BadMethodCallException('Not yet implemented');
    }

    public function fileSize(string $path): int
    {
        throw new \BadMethodCallException('Not yet implemented');
    }

    public function mimeType(string $path): string
    {
        throw new \BadMethodCallException('Not yet implemented');
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw new \BadMethodCallException('Not yet implemented');
    }

    public function visibility(string $path): string
    {
        throw new \BadMethodCallException('Not yet implemented');
    }


    public function directoryExists(string $location): bool
    {
        throw new \BadMethodCallException('Not yet implemented');
    }

    public function has(string $location): bool
    {
        throw new \BadMethodCallException('Not yet implemented');
    }
}
