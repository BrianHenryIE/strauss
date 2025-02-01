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
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use Traversable;

class ReadOnlyFileSystem implements FilesystemOperator, FlysystemBackCompatInterface
{
//  use FlysystemBackCompatTrait;
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

    private function getAttributes(string $path): StorageAttributes
    {
        $parentDirectoryContents = $this->listContents(dirname($path), false);
        /** @var FileAttributes $entry */
        foreach ($parentDirectoryContents as $entry) {
            if ($entry->path() == $path) {
                return $entry;
            }
        }
        throw UnableToReadFile::fromLocation($path);
    }

    public function lastModified(string $path): int
    {
        $attributes = $this->getAttributes($path);
        return $attributes->lastModified() ?? 0;
    }

    public function fileSize(string $path): int
    {
        $filesize = 0;

        if ($this->inMemoryFiles->fileExists($path)) {
            $filesize = $this->inMemoryFiles->fileSize($path);
        }

        if ($this->filesystem->fileExists($path)) {
            $filesize = $this->filesystem->fileSize($path);
        }

        if ($filesize instanceof FileAttributes) {
            return $filesize->fileSize();
        }

        return $filesize;
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
        if (!$this->fileExists($path) && !$this->directoryExists($path)) {
            throw UnableToRetrieveMetadata::visibility($path, 'file does not exist');
        }

        if ($this->deletedFiles->fileExists($path)) {
            throw UnableToRetrieveMetadata::visibility($path, 'file does not exist');
        }
        if ($this->inMemoryFiles->fileExists($path)) {
            $attribtes = $this->inMemoryFiles->visibility($path);
            return $attribtes->visibility();
        }
        if ($this->filesystem->fileExists($path)) {
            return $this->filesystem->visibility($path);
        }
        return \League\Flysystem\Visibility::PUBLIC;
    }

    public function directoryExists(string $location): bool
    {
        if ($this->directoryExistsIn($location, $this->deletedFiles)) {
            return false;
        }

        return  $this->directoryExistsIn($location, $this->inMemoryFiles)
            || $this->directoryExistsIn($location, $this->filesystem);
    }

    protected function directoryExistsIn(string $location, $filesystem): bool
    {
        if (method_exists($filesystem, 'directoryExists')
            && $filesystem->directoryExists($location)) {
            return true;
        }

        $parentDirectoryContents = $filesystem->listContents(dirname($location), false);
        /** @var FileAttributes $entry */
        foreach ($parentDirectoryContents as $entry) {
            if ($entry->path() == $location) {
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
