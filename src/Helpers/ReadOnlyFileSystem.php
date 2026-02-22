<?php
/**
 * When running with `--dry-run` the filesystem should be read-only.
 *
 * This should work with read operations working as normal but write operations should be
 * cached so they appear to have been successful but are not actually written to disk.
 */

namespace BrianHenryIE\Strauss\Helpers;

use BadMethodCallException;
use Exception;
use League\Flysystem\Config;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\FilesystemReader;
use League\Flysystem\PathNormalizer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\Visibility;
use League\Flysystem\WhitespacePathNormalizer;
use Traversable;

class ReadOnlyFileSystem implements FilesystemOperator, FlysystemBackCompatInterface
{
//  use FlysystemBackCompatTrait;
    protected FilesystemOperator $filesystem;
    protected InMemoryFilesystemAdapter $inMemoryFiles;
    protected InMemoryFilesystemAdapter $deletedFiles;

    protected PathNormalizer $pathNormalizer;

    public function __construct(FilesystemOperator $filesystem, ?PathNormalizer $pathNormalizer = null)
    {
        $this->filesystem = $filesystem;

        $this->inMemoryFiles = new InMemoryFilesystemAdapter();
        $this->deletedFiles = new InMemoryFilesystemAdapter();

        $this->pathNormalizer = $pathNormalizer ?? new WhitespacePathNormalizer();
    }

    public function fileExists(string $location): bool
    {
        $location = $this->pathNormalizer->normalizePath($location);

        if ($this->deletedFiles->fileExists($location)) {
            return false;
        }
        return $this->inMemoryFiles->fileExists($location)
                || $this->filesystem->fileExists($location);
    }

    /**
     * @param array{visibility?:string} $config
     * @throws FilesystemException
     */
    public function write(string $location, string $contents, array $config = []): void
    {
        $location = $this->pathNormalizer->normalizePath($location);

        $config = new Config($config);
        $this->inMemoryFiles->write($location, $contents, $config);

        if ($this->deletedFiles->fileExists($location)) {
            $this->deletedFiles->delete($location);
        }
    }

    /**
     * @param resource $contents
     * @param array{visibility?:string} $config
     * @throws FilesystemException
     */
    public function writeStream(string $location, $contents, $config = []): void
    {
        $location = $this->pathNormalizer->normalizePath($location);

        $config = new Config($config);
        $this->rewindStream($contents);
        $this->inMemoryFiles->writeStream($location, $contents, $config);

        if ($this->deletedFiles->fileExists($location)) {
            $this->deletedFiles->delete($location);
        }
    }
    /**
     * @param resource $resource
     */
    private function rewindStream($resource): void
    {
        if (ftell($resource) !== 0 && stream_get_meta_data($resource)['seekable']) {
            rewind($resource);
        }
    }

    public function read(string $location): string
    {
        $location = $this->pathNormalizer->normalizePath($location);

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
        $location = $this->pathNormalizer->normalizePath($location);

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
        $location = $this->pathNormalizer->normalizePath($location);

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
        $location = $this->pathNormalizer->normalizePath($location);

        $this->deletedFiles->createDirectory($location, new Config([]));
        $this->inMemoryFiles->deleteDirectory($location);
    }

    /**
     * @param array{visibility?:string} $config
     * @throws FilesystemException
     */
    public function createDirectory(string $location, array $config = []): void
    {
        $location = $this->pathNormalizer->normalizePath($location);

        $this->inMemoryFiles->createDirectory($location, new Config($config));

        $this->deletedFiles->deleteDirectory($location);
    }

    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        $location = $this->pathNormalizer->normalizePath($location);

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

    /**
     * @param array{visibility?:string} $config
     */
    public function move(string $source, string $destination, array $config = []): void
    {
        throw new BadMethodCallException('Not yet implemented');
    }

    /**
     * @param Config|array{visibility?:string}|null $config
     * @throws FilesystemException
     * @throws Exception
     */
    public function copy(string $source, string $destination, $config = null): void
    {
        $source = $this->pathNormalizer->normalizePath($source);
        $destination = $this->pathNormalizer->normalizePath($destination);

        $sourceFile = $this->read($source);

        $this->inMemoryFiles->write(
            $destination,
            $sourceFile,
            $config instanceof Config ? $config : new Config($config ?? [])
        );

        $a = $this->inMemoryFiles->read($destination);
        if ($sourceFile !== $a) {
            throw new Exception('Copy failed');
        }

        if ($this->deletedFiles->fileExists($destination)) {
            $this->deletedFiles->delete($destination);
        }
    }

    /**
     * @throws FilesystemException
     */
    private function getAttributes(string $path): StorageAttributes
    {
        $path = $this->pathNormalizer->normalizePath($path);

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
        $attributes = $this->getAttributes($this->pathNormalizer->normalizePath($path));
        return $attributes->lastModified() ?? 0;
    }

    public function fileSize(string $path): int
    {
        $path = $this->pathNormalizer->normalizePath($path);

        $filesize = 0;

        if ($this->inMemoryFiles->fileExists($path)) {
            $filesize = $this->inMemoryFiles->fileSize($path);
        } elseif ($this->filesystem->fileExists($path)) {
            $filesize = $this->filesystem->fileSize($path);
        }

        if ($filesize instanceof FileAttributes) {
            return $filesize->fileSize() ?? 0;
        }

        return $filesize;
    }

    public function mimeType(string $path): string
    {
        throw new BadMethodCallException('Not yet implemented');
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw new BadMethodCallException('Not yet implemented');
    }

    public function visibility(string $path): string
    {
        $defaultVisibility = Visibility::PUBLIC;

        $path = $this->pathNormalizer->normalizePath($path);

        if (!$this->fileExists($path) && !$this->directoryExists($path)) {
            throw UnableToRetrieveMetadata::visibility($path, 'file does not exist');
        }

        if ($this->deletedFiles->fileExists($path)) {
            throw UnableToRetrieveMetadata::visibility($path, 'file does not exist');
        }
        if ($this->inMemoryFiles->fileExists($path)) {
            $attributes = $this->inMemoryFiles->visibility($path);
            return $attributes->visibility() ?? $defaultVisibility;
        }
        if ($this->filesystem->fileExists($path)) {
            return $this->filesystem->visibility($path);
        }
        return $defaultVisibility;
    }

    public function directoryExists(string $location): bool
    {
        $location = $this->pathNormalizer->normalizePath($location);

        if ($this->directoryExistsIn($location, $this->deletedFiles)) {
            return false;
        }

        return  $this->directoryExistsIn($location, $this->inMemoryFiles)
            || $this->directoryExistsIn($location, $this->filesystem);
    }

    /**
     *
     * @param string $location
     * @param object|FilesystemReader $filesystem
     * @return bool
     * @throws FilesystemException
     */
    protected function directoryExistsIn(string $location, $filesystem): bool
    {
        $location = $this->pathNormalizer->normalizePath($location);

        if (method_exists($filesystem, 'directoryExists')) {
            return $filesystem->directoryExists($location);
        }

        /** @var FileSystemReader $filesystem */
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
        throw new BadMethodCallException('Not yet implemented');
    }
}
