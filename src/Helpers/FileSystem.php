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

use Elazar\Flystream\StripProtocolPathNormalizer;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\FilesystemReader;
use League\Flysystem\PathNormalizer;
use League\Flysystem\StorageAttributes;

class FileSystem implements FilesystemOperator, FlysystemBackCompatInterface
{
    use FlysystemBackCompatTrait;

    protected FilesystemOperator $flysystem;
    protected PathNormalizer $normalizer;

    /**
     * TODO: maybe restrict the constructor to only accept a LocalFilesystemAdapter.
     *
     * TODO: Check are any of these methods unused
     */
    public function __construct(FilesystemOperator $flysystem)
    {
        $this->flysystem = $flysystem;
        $this->normalizer = new StripProtocolPathNormalizer('mem');
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

            if (!$this->directoryExists($path)) {
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
        return $this->flysystem->fileExists(
            $this->normalizer->normalizePath($location)
        );
    }

    public function read(string $location): string
    {
        return $this->flysystem->read(
            $this->normalizer->normalizePath($location)
        );
    }

    public function readStream(string $location)
    {
        return $this->flysystem->readStream(
            $this->normalizer->normalizePath($location)
        );
    }

    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        return $this->flysystem->listContents(
            $this->normalizer->normalizePath($location),
            $deep
        );
    }

    public function lastModified(string $path): int
    {
        return $this->flysystem->lastModified(
            $this->normalizer->normalizePath($path)
        );
    }

    public function fileSize(string $path): int
    {
        return $this->flysystem->fileSize(
            $this->normalizer->normalizePath($path)
        );
    }

    public function mimeType(string $path): string
    {
        return $this->flysystem->mimeType(
            $this->normalizer->normalizePath($path)
        );
    }

    public function visibility(string $path): string
    {
        return $this->flysystem->visibility(
            $this->normalizer->normalizePath($path)
        );
    }

    public function write(string $location, string $contents, array $config = []): void
    {
        $this->flysystem->write(
            $this->normalizer->normalizePath($location),
            $contents,
            $config
        );
    }

    public function writeStream(string $location, $contents, array $config = []): void
    {
        $this->flysystem->writeStream(
            $this->normalizer->normalizePath($location),
            $contents,
            $config
        );
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->flysystem->setVisibility(
            $this->normalizer->normalizePath($path),
            $visibility
        );
    }

    public function delete(string $location): void
    {
        $this->flysystem->delete(
            $this->normalizer->normalizePath($location)
        );
    }

    public function deleteDirectory(string $location): void
    {
        $this->flysystem->deleteDirectory(
            $this->normalizer->normalizePath($location)
        );
    }

    public function createDirectory(string $location, array $config = []): void
    {
        $this->flysystem->createDirectory(
            $this->normalizer->normalizePath($location),
            $config
        );
    }

    public function move(string $source, string $destination, array $config = []): void
    {
        $this->flysystem->move(
            $this->normalizer->normalizePath($source),
            $this->normalizer->normalizePath($destination),
            $config
        );
    }

    public function copy(string $source, string $destination, array $config = []): void
    {
        $this->flysystem->copy(
            $this->normalizer->normalizePath($source),
            $this->normalizer->normalizePath($destination),
            $config
        );
    }

    /**
     *
     * /path/to/this/dir, /path/to/file.php => ../../file.php
     * /path/to/here, /path/to/here/dir/file.php => dir/file.php
     *
     * @param string $fromAbsoluteDirectory
     * @param string $toAbsolutePath
     * @return string
     */
    public function getRelativePath(string $fromAbsoluteDirectory, string $toAbsolutePath): string
    {
        $fromAbsoluteDirectory = $this->normalizer->normalizePath($fromAbsoluteDirectory);
        $toAbsolutePath = $this->normalizer->normalizePath($toAbsolutePath);

        $fromDirectoryParts = array_filter(explode('/', $fromAbsoluteDirectory));
        $toPathParts = array_filter(explode('/', $toAbsolutePath));
        foreach ($fromDirectoryParts as $key => $part) {
            if ($part === $toPathParts[$key]) {
                unset($toPathParts[$key]);
                unset($fromDirectoryParts[$key]);
            } else {
                break;
            }
            if (count($fromDirectoryParts) === 0 || count($toPathParts) === 0) {
                break;
            }
        }

        $relativePath =
            str_repeat('../', count($fromDirectoryParts))
            . implode('/', $toPathParts);

        return $relativePath;
    }
}
