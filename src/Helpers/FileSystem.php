<?php
/**
 * This class extends Flysystem's Filesystem class to add some additional functionality, particularly around
 * symlinks which are not supported by Flysystem.
 *
 * TODO: Delete and modify operations on files in symlinked directories should fail with a warning.
 *
 * @see https://github.com/thephpleague/flysystem/issues/599
 */

namespace BrianHenryIE\Strauss\Helpers;

use League\Flysystem\DirectoryAttributes;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\FilesystemReader;
use League\Flysystem\StorageAttributes;

class FileSystem implements FilesystemOperator
{
    protected FilesystemOperator $flysystem;

    /**
     * TODO: maybe restrict the constructor to only accept a LocalFilesystemAdapter.
     */
    public function __construct(FilesystemOperator $flysystem)
    {
        $this->flysystem = $flysystem;
    }

    /**
     * @param string $workingDir
     * @param string[] $relativeFileAndDirPaths
     *
     * @return string[]
     */
    public function findAllFilesAbsolutePaths(string $workingDir, array $relativeFileAndDirPaths): array
    {
        $files = [];

        foreach ($relativeFileAndDirPaths as $path) {
            // If the path begins with the workingDir just use the path, otherwise concatenate them
            $path = strpos($path, rtrim($workingDir, '/\\')) === 0 ? $path : $workingDir . $path;

            if (!$this->isDir($path)) {
                $files[] = $path;
                continue;
            }

            $directoryListing = $this->listContents(
                $path,
                FilesystemReader::LIST_DEEP
            );

            /** @var FileAttributes[] $files */
            $fileAttributesArray = $directoryListing->toArray();

            $f = array_map(fn($file) => '/'.$file->path(), $fileAttributesArray);

            $files = array_merge($files, $f);
        }

        return $files;
    }

    public function isDir(string $path): bool
    {
        return is_dir($path);

        if (strpos($path, '/.') === strlen($path) - 2) {
            $path = rtrim($path, '.');
        }
        $attributes = $this->getAttributes($path);
        return $attributes instanceof DirectoryAttributes;
    }

    public function getAttributes(string $absolutePath): ?StorageAttributes
    {
        $fileDirectory = realpath(dirname($absolutePath));

        // Unsupported symbolic link encountered at location //home
        // \League\Flysystem\SymbolicLinkEncountered
        $dirList = $this->listContents($fileDirectory)->toArray();
        foreach ($dirList as $file) { // TODO: use the generator.
            if ($file->path() === trim($absolutePath, DIRECTORY_SEPARATOR)) {
                return $file;
            }
        }

        return null;
    }

    public function fileExists(string $location): bool
    {
        return $this->flysystem->fileExists($location);
    }

    public function read(string $location): string
    {
        return $this->flysystem->read($location);
    }

    public function readStream(string $location)
    {
        return $this->flysystem->readStream($location);
    }

    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        return $this->flysystem->listContents($location, $deep);
    }

    public function lastModified(string $path): int
    {
        return $this->flysystem->lastModified($path);
    }

    public function fileSize(string $path): int
    {
        return $this->flysystem->fileSize($path);
    }

    public function mimeType(string $path): string
    {
        return $this->flysystem->mimeType($path);
    }

    public function visibility(string $path): string
    {
        return $this->flysystem->visibility($path);
    }

    public function write(string $location, string $contents, array $config = []): void
    {
        $this->flysystem->write($location, $contents, $config);
    }

    public function writeStream(string $location, $contents, array $config = []): void
    {
        $this->flysystem->writeStream($location, $contents, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->flysystem->setVisibility($path, $visibility);
    }

    public function delete(string $location): void
    {
        $this->flysystem->delete($location);
    }

    public function deleteDirectory(string $location): void
    {
        $this->flysystem->deleteDirectory($location);
    }

    public function createDirectory(string $location, array $config = []): void
    {
        $this->flysystem->createDirectory($location, $config);
    }

    public function move(string $source, string $destination, array $config = []): void
    {
        $this->flysystem->move($source, $destination, $config);
    }

    public function copy(string $source, string $destination, array $config = []): void
    {
        $this->flysystem->copy($source, $destination, $config);
    }

    // Some version of Flysystem has:
    // directoryExists
    public function directoryExists(string $location): bool
    {
        if (method_exists($this->flysystem, 'directoryExists')) {
            return $this->flysystem->directoryExists($location);
        }
        return is_dir($location);
    }

    // Some version of Flysystem has:
    // has
    public function has(string $location): bool
    {
        if (method_exists($this->flysystem, 'has')) {
            return $this->flysystem->has($location);
        }
        return $this->fileExists($location) || $this->directoryExists($location);
    }
}
