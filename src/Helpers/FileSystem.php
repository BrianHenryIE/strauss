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

use BrianHenryIE\Strauss\Files\FileBase;
use Elazar\Flystream\StripProtocolPathNormalizer;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\FilesystemReader;
use League\Flysystem\PathNormalizer;
use League\Flysystem\StorageAttributes;

class FileSystem implements FilesystemOperator, FlysystemBackCompatInterface
{
    use FlysystemBackCompatTrait;

    protected FilesystemOperator $flysystem;

    protected PathNormalizer $normalizer;

    protected string $workingDir;

    /**
     * TODO: maybe restrict the constructor to only accept a LocalFilesystemAdapter.
     *
     * TODO: Check are any of these methods unused
     */
    public function __construct(FilesystemOperator $flysystem, string $workingDir)
    {
        $this->flysystem = $flysystem;
        $this->normalizer = new StripProtocolPathNormalizer('mem');

        $this->workingDir = $workingDir;
    }

    /**
     * @param string[] $fileAndDirPaths
     *
     * @return string[]
     * @throws FilesystemException
     */
    public function findAllFilesAbsolutePaths(array $fileAndDirPaths, bool $excludeDirectories = false): array
    {
        $files = [];

        foreach ($fileAndDirPaths as $path) {
            if (!$this->directoryExists($path)) {
                $files[] = $path;
                continue;
            }

            $directoryListing = $this->listContents(
                $path,
                FilesystemReader::LIST_DEEP
            );

            /** @var FileAttributes[] $fileAttributesArray */
            $fileAttributesArray = $directoryListing->toArray();


            $f = array_map(
                fn(StorageAttributes $attributes): string => '/'.$attributes->path(),
                $fileAttributesArray
            );

            if ($excludeDirectories) {
                $f = array_filter($f, fn($path) => !$this->directoryExists($path));
            }

            $files = array_merge($files, $f);
        }

        return $files;
    }

    /**
     * @throws FilesystemException
     */
    public function getAttributes(string $absolutePath): ?StorageAttributes
    {
        // TODO: check if `realpath()` is a bad idea here.
        $fileDirectory = realpath(dirname($absolutePath)) ?: dirname($absolutePath);

        $absolutePath = $this->normalizer->normalizePath($absolutePath);

        // Unsupported symbolic link encountered at location //home
        // \League\Flysystem\SymbolicLinkEncountered
        $dirList = $this->listContents($fileDirectory)->toArray();
        foreach ($dirList as $file) { // TODO: use the generator.
            if ($file->path() === $absolutePath) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @throws FilesystemException
     */
    public function exists(string $location): bool
    {
        return $this->fileExists($location) || $this->directoryExists($location);
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

    /**
     * @param array{visibility?:string} $config
     * @throws FilesystemException
     */
    public function write(string $location, string $contents, array $config = []): void
    {
        $this->flysystem->write(
            $this->normalizer->normalizePath($location),
            $contents,
            $config
        );
    }

    /**
     * @param array{visibility?:string} $config
     * @throws FilesystemException
     */
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

    /**
     * @param array{visibility?:string} $config
     * @throws FilesystemException
     */
    public function createDirectory(string $location, array $config = []): void
    {
        $this->flysystem->createDirectory(
            $this->normalizer->normalizePath($location),
            $config
        );
    }

    /**
     * @param array{visibility?:string} $config
     * @throws FilesystemException
     */
    public function move(string $source, string $destination, array $config = []): void
    {
        $this->flysystem->move(
            $this->normalizer->normalizePath($source),
            $this->normalizer->normalizePath($destination),
            $config
        );
    }

    /**
     * @param array{visibility?:string} $config
     * @throws FilesystemException
     */
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

        if ($this->directoryExists($toAbsolutePath)) {
            $relativePath .= '/';
        }

        return $relativePath;
    }

    public function getProjectRelativePath(string $absolutePath): string
    {

        // What will happen with strings that are not paths?!

        return $this->getRelativePath(
            $this->workingDir,
            $absolutePath
        );
    }

    /**
     * Check does the filepath point to a file outside the working directory.
     * If `realpath()` fails to resolve the path, assume it's a symlink.
     */
    public function isSymlinkedFile(FileBase $file): bool
    {
        $realpath = realpath($file->getSourcePath());

        return ! $realpath || ! str_starts_with($realpath, $this->workingDir);
    }

    /**
     * Does the subDir path start with the dir path?
     */
    public function isSubDirOf(string $dir, string $subDir): bool
    {
        return str_starts_with(
            $this->normalizer->normalizePath($subDir),
            $this->normalizer->normalizePath($dir)
        );
    }

    public function normalize(string $path): string
    {
        return $this->normalizer->normalizePath($path);
    }

    /**
     * Normalize a path and ensure it's absolute.
     *
     * Flysystem's normalizer strips leading slashes because paths are relative to the adapter root.
     * When we need paths for external use (Composer, realpath, etc.), they must be absolute.
     *
     * - On Unix: prepends '/' if not present
     * - On Windows: paths already have drive letters (e.g., 'C:/...') so no prefix needed
     */
    public function makeAbsolute(string $path): string
    {
        $normalized = $this->normalizer->normalizePath($path);

        // Windows paths start with drive letter (e.g., 'C:/' or 'D:\')
        if (preg_match('/^[a-zA-Z]:/', $normalized)) {
            return $normalized;
        }

        // Unix paths need leading slash
        if (!str_starts_with($normalized, '/')) {
            return '/' . $normalized;
        }

        return $normalized;
    }
}
