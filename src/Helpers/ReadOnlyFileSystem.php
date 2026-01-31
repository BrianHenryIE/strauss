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
use Elazar\Flystream\StripProtocolPathNormalizer;
use League\Flysystem\Config;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\FilesystemReader;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PathNormalizer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\Visibility;
use League\Flysystem\WhitespacePathNormalizer;
use Traversable;

// TODO: When a directory is deleted, all the files in that directory should be marked deleted?
// OR each parent diectory of a file should be checked it exists before the file is read?

class ReadOnlyFileSystem implements FilesystemAdapter, FlysystemBackCompatTraitInterface
{
    use FlysystemBackCompatTrait;

    protected FilesystemAdapter $delegateFilesystemAdapter;
    protected ModifiedFilesInMemoryFilesystemAdapter $inMemoryFiles;
    protected DeletedFilesInMemoryFilesystemAdapter $deletedFiles;

    protected PathNormalizer $pathNormalizer;

    public function __construct(
        FilesystemAdapter $delegateFilesystem,
        ?PathNormalizer $pathNormalizer = null
    ) {
        $this->delegateFilesystemAdapter = $delegateFilesystem;

        $this->inMemoryFiles = new ModifiedFilesInMemoryFilesystemAdapter();
        $this->deletedFiles = new DeletedFilesInMemoryFilesystemAdapter();

        $this->pathNormalizer = $pathNormalizer ?? new WhitespacePathNormalizer();
    }

    public function getAdapter(): FilesystemAdapter
    {
        return $this->delegateFilesystemAdapter;
    }

    public function fileExists(string $path): bool
    {
        if ($this->deletedFiles->fileExists($path)) {
            return false;
        }
        return $this->inMemoryFiles->fileExists($path)
                || $this->delegateFilesystemAdapter->fileExists($path);
    }

    /**
     * @param Config|array{visibility?:string} $config
     * @throws FilesystemException
     */
    public function write(string $path, string $contents, $config): void
    {
        $configObject = $config instanceof Config ? $config : new Config($config);
        $this->inMemoryFiles->write($path, $contents, $configObject);

        if ($this->deletedFiles->fileExists($path)) {
            $this->deletedFiles->delete($path);
        }
    }

    /**
     * @see FilesystemAdapter::writeStream()
     * @param resource $contents
     * @param array{visibility?:string} $config
     * @throws FilesystemException
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $config = new Config($config);
        $this->rewindStream($contents);
        $this->inMemoryFiles->writeStream($path, $contents, $config);

        if ($this->deletedFiles->fileExists($path)) {
            $this->deletedFiles->delete($path);
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

    public function read(string $path): string
    {
        if ($this->deletedFiles->fileExists($path)) {
            throw UnableToReadFile::fromLocation($path);
        }
        if ($this->inMemoryFiles->fileExists($path)) {
            return $this->inMemoryFiles->read($path);
        }
        return $this->delegateFilesystemAdapter->read($path);
    }

    public function readStream(string $path)
    {
        if ($this->deletedFiles->fileExists($path)) {
            throw UnableToReadFile::fromLocation($path);
        }
        if ($this->inMemoryFiles->fileExists($path)) {
            return $this->inMemoryFiles->readStream($path);
        }
        return $this->delegateFilesystemAdapter->readStream($path);
    }

    public function delete(string $path): void
    {
        if ($this->fileExists($path)) {
            $file = $this->read($path);
            $this->deletedFiles->write($path, $file, new Config([]));
        }
        if ($this->inMemoryFiles->fileExists($path)) {
            $this->inMemoryFiles->delete($path);
        }
    }

    public function deleteDirectory(string $path): void
    {
        $path = $this->pathNormalizer->normalizePath($path);

        $this->deletedFiles->createDirectory($path, new Config([]));
        $this->inMemoryFiles->deleteDirectory($path);
    }

    /**
     * @param Config|array{visibility?:string} $config
     * @throws FilesystemException
     */
    public function createDirectory(string $path, $config = []): void
    {
        $this->inMemoryFiles->createDirectory(
            $path,
            $config instanceof Config ? $config : new Config($config)
        );

        $this->deletedFiles->deleteDirectory($path);
    }

    public function listContents(string $path, bool $deep): iterable
    {

        $deletedFilesGenerator = $this->deletedFiles->listContents($path, $deep);
        $deletedFilesArray = $deletedFilesGenerator instanceof Traversable
            ? iterator_to_array($deletedFilesGenerator, false)
            : (array) $deletedFilesGenerator;
        $deletedFilePaths = array_map(fn($file) => $file->path(), $deletedFilesArray);


        $inMemoryFilesGenerator = $this->inMemoryFiles->listContents($path, $deep);
        $inMemoryFilesArray = $inMemoryFilesGenerator instanceof Traversable
            ? iterator_to_array($inMemoryFilesGenerator, false)
            : (array) $inMemoryFilesGenerator;

        // Remove deleted files from the modified files filesystem array
        $inMemoryFilesArray = array_filter($inMemoryFilesArray, fn($file) => !in_array($file->path(), $deletedFilePaths));

        $inMemoryFilePaths = (array) array_map(fn($file) => $file->path(), $inMemoryFilesArray);


        /** @var FileAttributes[] $parentFilesystemArray */
        $parentFilesystemGenerator = $this->delegateFilesystemAdapter->listContents($path, $deep);
        $parentFilesystemArray = $parentFilesystemGenerator instanceof Traversable
            ? iterator_to_array($parentFilesystemGenerator, false)
            : (array) $parentFilesystemGenerator;
//      $parentFilesystemPaths = (array) array_map(fn($file) => $file->path(), $parentFilesystemArray);

        // Remove modified files from the parent filesystem array
        $parentFilesystemArray = array_filter($parentFilesystemArray, fn($file) => !in_array($file->path(), $inMemoryFilePaths));
        // Remove deleted files from the parent filesystem array
        $parentFilesystemArray = array_filter($parentFilesystemArray, fn($file) => !in_array($file->path(), $deletedFilePaths));

        $good = array_merge($parentFilesystemArray, $inMemoryFilesArray);

        return new DirectoryListing($good);
    }

    /**
     * @param Config|array{visibility?:string} $config
     */
    public function move(string $source, string $destination, $config): void
    {
        throw new BadMethodCallException('Not yet implemented');
    }

    /**
     * @see FilesystemAdapter::copy()
     *
     * @param Config|array{visibility?:string}|null $config
     * @throws FilesystemException
     * @throws Exception
     */
    public function copy(string $source, string $destination, $config = null): void
    {
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
        $parentDirectoryContents = $this->listContents(dirname($path), false);
        /** @var FileAttributes $entry */
        foreach ($parentDirectoryContents as $entry) {
            if ($entry->path() == $path) {
                return $entry;
            }
        }
        throw UnableToReadFile::fromLocation($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        $storageAttributes = $this->getAttributes($path);
        return new FileAttributes(
            $path,
            null,
            $storageAttributes->visibility(),
            // TODO: This shouldn't be null – it should be set during other operations.
            $storageAttributes->lastModified() ?? 0
        );
    }

    public function fileSize(string $path): FileAttributes
    {
        $filesize = 0;

        if ($this->inMemoryFiles->fileExists($path)) {
            $filesize = $this->inMemoryFiles->fileSize($path);
        } elseif ($this->delegateFilesystemAdapter->fileExists($path)) {
            $filesize = $this->delegateFilesystemAdapter->fileSize($path);
        }

        if ($filesize instanceof FileAttributes) {
            return $filesize;
        }

        return $filesize;
    }

    public function mimeType(string $path): FileAttributes
    {
        throw new BadMethodCallException('Not yet implemented');
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw new BadMethodCallException('Not yet implemented');
    }

    public function visibility(string $path): FileAttributes
    {
        $defaultVisibility = Visibility::PUBLIC;

        $path = $this->pathNormalizer->normalizePath($path);

        if (!$this->has($path)) {
            throw UnableToRetrieveMetadata::visibility($path, 'file does not exist');
        }

        if ($this->deletedFiles->has($path)) {
            throw UnableToRetrieveMetadata::visibility($path, 'file does not exist');
        }

        if ($this->inMemoryFiles->has($path)) {
            return $this->inMemoryFiles->visibility($path);
        }

        return $this->delegateFilesystemAdapter->visibility($path);
    }

    public function directoryExists(string $path): bool
    {
        $path = $this->pathNormalizer->normalizePath($path);

        if ($this->directoryExistsIn($path, $this->deletedFiles)) {
            return false;
        }

        return  $this->directoryExistsIn($path, $this->inMemoryFiles)
            || $this->directoryExistsIn($path, $this->delegateFilesystemAdapter);
    }

    /**
     *
     * @param string $path
     * @param object|FilesystemReader $filesystem
     * @return bool
     * @throws FilesystemException
     */
    protected function directoryExistsIn(string $path, FilesystemAdapter $filesystem): bool
    {
        if (method_exists($filesystem, 'directoryExists')) {
            return $filesystem->directoryExists($path);
        }

        $parentDirectoryPath = dirname($path);

        /** @var FileSystemReader $filesystem */
        $parentDirectoryContents = $filesystem->listContents($parentDirectoryPath, false);

        $parent = [];
        /** @var FileAttributes $entry */
        foreach ($parentDirectoryContents as $entry) {
            $parent[] = $entry;
            if ($entry->path() == $path) {
                return $entry->isDir();
            }
        }

        return false;
    }

    public function has(string $location): bool
    {
        throw new BadMethodCallException('Not yet implemented');
    }

    /**
     * @see FlysystemBackCompatTrait::directoryExists()
     */
    public function getNormalizer(): PathNormalizer
    {
        return $this->pathNormalizer;
    }
}
