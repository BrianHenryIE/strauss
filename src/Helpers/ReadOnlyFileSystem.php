<?php
/**
 * When running with `--dry-run` the filesystem should be read-only.
 *
 * This should work with read operations working as normal but write operations should be
 * cached so they appear to have been successful but are not actually written to disk.
 */

namespace BrianHenryIE\Strauss\Helpers;

use League\Flysystem\Config;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use Traversable;

class ReadOnlyFileSystem implements FilesystemOperator
{
    protected FilesystemOperator $filesystem;
    protected InMemoryFilesystemAdapter $inMemoryFiles;
    protected InMemoryFilesystemAdapter $deletedFiles;

    public function __construct(FilesystemOperator $filesystem)
    {
        $this->filesystem = $filesystem;

        $this->inMemoryFiles = new InMemoryFilesystemAdapter();
        $this->deletedFiles = new InMemoryFilesystemAdapter();
    }

    public function fileExists(string $location): bool
    {
        if ($this->deletedFiles->fileExists($location)) {
            return false;
        }
        return $this->inMemoryFiles->fileExists($location)
                || $this->filesystem->fileExists($location);
    }

    public function write(string $location, string $contents, array $config = []): void
    {
        $config = new \League\Flysystem\Config($config);
        $this->inMemoryFiles->write($location, $contents, $config);

        if ($this->deletedFiles->fileExists($location)) {
            $this->deletedFiles->delete($location);
        }
    }

    public function writeStream(string $location, $contents, array $config = []): void
    {
        $config = new \League\Flysystem\Config($config);
        $this->inMemoryFiles->writeStream($location, $contents, $config);

        if ($this->deletedFiles->fileExists($location)) {
            $this->deletedFiles->delete($location);
        }
    }

    public function read(string $location): string
    {
        if ($this->deletedFiles->fileExists($location)) {
            throw UnableToReadFile::fromLocation($location);
        }
        if ($this->inMemoryFiles->fileExists($location)) {
            return $this->inMemoryFiles->read($location);
        }
        return $this->filesystem->read($location);
    }

    public function readStream(string $location)
    {
        if ($this->deletedFiles->fileExists($location)) {
            throw UnableToReadFile::fromLocation($location);
        }
        if ($this->inMemoryFiles->fileExists($location)) {
            return $this->inMemoryFiles->readStream($location);
        }
        return $this->filesystem->readStream($location);
    }

    public function delete(string $location): void
    {
        if ($this->fileExists($location)) {
            $file = $this->read($location);
            $this->deletedFiles->write($location, $file, new Config([]));
        }
        if ($this->inMemoryFiles->fileExists($location)) {
            $this->inMemoryFiles->delete($location);
        }
    }

    public function deleteDirectory(string $location): void
    {
        $fileContents = $this->read($location);
        $this->deletedFiles->write($location, $fileContents, new Config([]));
        $this->inMemoryFiles->delete($location);
    }


    public function createDirectory(string $location, array $config = []): void
    {
        $this->inMemoryFiles->createDirectory($location, new Config($config));

        $this->deletedFiles->deleteDirectory($location);
    }

    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        /** @var FileAttributes[] $actual */
        $actual = $this->filesystem->listContents($location, $deep)->toArray();

        $inMemoryFilesGenerator = $this->inMemoryFiles->listContents($location, $deep);
        $inMemoryFilesArray = $inMemoryFilesGenerator instanceof Traversable
            ? iterator_to_array($inMemoryFilesGenerator, false)
            : (array) $inMemoryFilesGenerator;

        $inMemoryFilePaths = array_map(fn($file) => $file->path(), $inMemoryFilesArray);

        $deletedFilesGenerator = $this->deletedFiles->listContents($location, $deep);
        $deletedFilesArray = $deletedFilesGenerator instanceof Traversable
            ? iterator_to_array($deletedFilesGenerator, false)
            : (array) $deletedFilesGenerator;
        $deletedFilePaths = array_map(fn($file) => $file->path(), $deletedFilesArray);

        $actual = array_filter($actual, fn($file) => !in_array($file->path(), $inMemoryFilePaths));
        $actual = array_filter($actual, fn($file) => !in_array($file->path(), $deletedFilePaths));

        $good = array_merge($actual, $inMemoryFilesArray);

        return new DirectoryListing($good);
    }

    public function move(string $source, string $destination, array $config = []): void
    {
        throw new \BadMethodCallException('Not yet implemented');
    }

    public function copy(string $source, string $destination, array $config = []): void
    {
        $sourceFile = $this->read($source);

        $this->inMemoryFiles->write($destination, $sourceFile, new Config($config));

        if ($this->deletedFiles->fileExists($destination)) {
            $this->deletedFiles->delete($destination);
        }
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
        if (method_exists($this->deletedFiles, 'directoryExists')
            && $this->deletedFiles->directoryExists($location)) {
            return false;
        }

        if (method_exists($this->inMemoryFiles, 'directoryExists')
            && $this->inMemoryFiles->directoryExists($location)) {
            return true;
        }

        if (method_exists($this->filesystem, 'directoryExists')
            && $this->filesystem->directoryExists($location)) {
            return true;
        }

        $parentDirectoryContents = $this->listContents(dirname($location));
        /** @var FileAttributes $entry */
        foreach ($parentDirectoryContents as $entry) {
            if ($entry->path()) {
                return $entry->isDir();
            }
        }

        return false;
    }

    public function has(string $location): bool
    {
        throw new \BadMethodCallException('Not yet implemented');
    }
}
