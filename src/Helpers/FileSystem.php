<?php
/**
 *
 */

namespace BrianHenryIE\Strauss\Helpers;

use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\FilesystemReader;
use League\Flysystem\StorageAttributes;

class FileSystem extends FlysystemFilesystem
{

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
        $attributes = $this->getAttributes($path);
        return $attributes instanceof DirectoryAttributes;
    }

    public function getAttributes(string $absolutePath): ?StorageAttributes
    {
        $fileDirectory = dirname($absolutePath);

        $dirList = $this->listContents($fileDirectory)->toArray();
        foreach ($dirList as $file) { // TODO: use the generator.
            if ($file->path() === trim($absolutePath, DIRECTORY_SEPARATOR)) {
                return $file;
            }
        }

        return null;
    }
}
