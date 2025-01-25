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
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\FilesystemReader;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PathNormalizer;
use League\Flysystem\StorageAttributes;

class FileSystem extends FlysystemFilesystem
{
    /**
     * Restrict the constructor to only accept a LocalFilesystemAdapter.
     *
     * @param LocalFilesystemAdapter $adapter
     * @param array $config
     * @param PathNormalizer|null $pathNormalizer
     */
    public function __construct(LocalFilesystemAdapter $adapter, array $config = [], PathNormalizer $pathNormalizer = null)
    {
        parent::__construct($adapter, $config, $pathNormalizer);
    }

    /**
     * @param string $workingDir
     * @param string[] $relativeFileAndDirPaths
     * @param string $regexPattern
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
}
