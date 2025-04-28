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
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemReader;
use League\Flysystem\PathNormalizer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\WhitespacePathNormalizer;

class FileSystem extends \League\Flysystem\Filesystem implements FlysystemBackCompatTraitInterface
{
    use FlysystemBackCompatTrait;

    protected PathNormalizer $normalizer;

    public function __construct(FilesystemAdapter $adapter, array $config = [], PathNormalizer $pathNormalizer = null)
    {

        parent::__construct($adapter, $config, $pathNormalizer);

        // Parent is private.
        $this->normalizer = $pathNormalizer ?? new WhitespacePathNormalizer();
        $this->adapter = $adapter;
    }

    public function getAdapter(): FilesystemAdapter
    {
        return $this->adapter;
    }

    /**
     * @param string[] $fileAndDirPaths
     *
     * @return string[]
     */
    public function findAllFilesAbsolutePaths(array $fileAndDirPaths): array
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


    /**
     * Does the subdir path start with the dir path?
     */
    public function isSubDirOf(string $dir, string $subdir): bool
    {
        return str_starts_with(
            $this->normalizer->normalizePath($subdir),
            $this->normalizer->normalizePath($dir)
        );
    }

    public function normalize(string $path)
    {
        return $this->normalizer->normalizePath($path);
    }


    /**
     * Check does the filepath point to a file outside the working directory.
     * If `realpath()` fails to resolve the path, assume it's a symlink.
     */
    public function isSymlinkedFile(FileBase $file): bool
    {
        $realpath = realpath('/'.$file->getSourcePath());

        // If realpath fails, it's probably an in-memory file.
        if (!$realpath) {
            return false;
        }

        return $file->getSourcePath() !== $realpath;
    }


    public function dirIsEmpty(string $dir): bool
    {
        // TODO BUG this deletes directories with only symlinks inside. How does it behave with hidden files?
        return empty($this->listContents($dir)->toArray());
    }

    /**
     * @see FlysystemBackCompatTrait::directoryExists()
     */
    public function getNormalizer(): PathNormalizer
    {
        return $this->normalizer;
    }
}
