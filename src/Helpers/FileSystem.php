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
use Exception;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\FilesystemReader;
use League\Flysystem\PathNormalizer;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;

class FileSystem implements FilesystemOperator, FlysystemBackCompatInterface, PathNormalizer
{
    use FlysystemBackCompatTrait;

    protected FilesystemOperator $flysystem;

    protected PathNormalizer $normalizer;

    protected PathPrefixer $pathPrefixer;

    /** No trailing slash */
    protected string $workingDir;

    /**
     * TODO: maybe restrict the constructor to only accept a LocalFilesystemAdapter.
     *
     * TODO: Check are any of these methods unused
     *
     * @param FilesystemOperator $flysystem
     * @param string $workingDir
     * @param ?string $flysystemRoot In practice we always use the root of the drive which can be inferred from workingDir but that's not strictly required.
     */
    public function __construct(
        FilesystemOperator $flysystem,
        string $workingDir,
        ?string $flysystemRoot = null
    ) {
        $this->flysystem = $flysystem;

        $this->normalizer = self::makePathNormalizer($workingDir);

        $this->workingDir = $workingDir;

        $this->pathPrefixer = new PathPrefixer(
            $flysystemRoot ?? self::getFsRoot($workingDir),
            DIRECTORY_SEPARATOR
        );
    }

    public static function getFsRoot(?string $path = null): string
    {
        if (1 === preg_match('/^([a-zA-z]+:[\\\\\/]|\/)/', $path ?? getcwd(), $output_array)) {
            return strtoupper($output_array[1]);
        }
        return '/';
    }

    public static function makePathNormalizer(string $workingDir): PathNormalizer
    {
        return new StripProtocolPathNormalizer(
            [
                'mem',
            ],
            new StripFsRootPathNormalizer(
                [
                    FileSystem::getFsRoot($workingDir),
                    Filesystem::getFsRoot(),
                    Filesystem::normalizeDirSeparator(FileSystem::getFsRoot()),
                    'c:\\',
                    'c:/',
                ]
            )
        );
    }

    /**
     * Normalize directory separators to forward slashes.
     *
     * PHP native functions (realpath, getcwd, dirname) return backslashes on Windows,
     * but Flysystem always uses forward slashes. This method ensures consistency.
     *
     * Accepts null to preserve original str_replace() behavior where null is treated as empty string.
     *
     * @param string|false|null $path
     */
    public static function normalizeDirSeparator($path, $slashTo = '/'): string
    {
        $slashFrom = $slashTo = '/' ? '\\' : '/';

        return str_replace($slashFrom, $slashTo, $path ?: '');
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
                fn(StorageAttributes $attributes): string => $this->makeAbsolute($attributes->path()),
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

        $absolutePath = $this->normalizePath($absolutePath);

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
        return $this->fileExists($location)
               || $this->directoryExists($location)
               || false !== realpath($this->pathPrefixer->prefixPath($this->normalizePath($location)));
    }

    public function fileExists(string $location): bool
    {
        return $this->flysystem->fileExists(
            $this->normalizePath($location)
        );
    }

    public function read(string $location): string
    {
        return $this->flysystem->read(
            $this->normalizePath($location)
        );
    }

    public function readStream(string $location)
    {
        return $this->flysystem->readStream(
            $this->normalizePath($location)
        );
    }

    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        return $this->flysystem->listContents(
            $this->normalizePath($location),
            $deep
        );
    }

    public function lastModified(string $path): int
    {
        return $this->flysystem->lastModified(
            $this->normalizePath($path)
        );
    }

    public function fileSize(string $path): int
    {
        return $this->flysystem->fileSize(
            $this->normalizePath($path)
        );
    }

    public function mimeType(string $path): string
    {
        return $this->flysystem->mimeType(
            $this->normalizePath($path)
        );
    }

    public function visibility(string $path): string
    {
        return $this->flysystem->visibility(
            $this->normalizePath($path)
        );
    }

    /**
     * @param array{visibility?:string} $config
     * @throws FilesystemException
     */
    public function write(string $location, string $contents, array $config = []): void
    {
        $this->flysystem->write(
            $this->normalizePath($location),
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
            $this->normalizePath($location),
            $contents,
            $config
        );
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->flysystem->setVisibility(
            $this->normalizePath($path),
            $visibility
        );
    }

    public function delete(string $location): void
    {
        $this->flysystem->delete(
            $this->normalizePath($location)
        );
    }

    public function deleteDirectory(string $location): void
    {
        $this->flysystem->deleteDirectory(
            $this->normalizePath($location)
        );
    }

    /**
     * @param array{visibility?:string} $config
     * @throws FilesystemException
     */
    public function createDirectory(string $location, array $config = []): void
    {
        $this->flysystem->createDirectory(
            $this->normalizePath($location),
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
            $this->normalizePath($source),
            $this->normalizePath($destination),
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
            $this->normalizePath($source),
            $this->normalizePath($destination),
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
        $fromAbsoluteDirectory = $this->normalizePath($fromAbsoluteDirectory);
        $toAbsolutePath = $this->normalizePath($toAbsolutePath);

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

        return rtrim($relativePath, '\\/');
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
     *
     * @throws FilesystemException
     * @throws Exception
     */
    public function isSymlinked(string $path): bool
    {
        $normalizedPath = $this->normalizePath($path);

        if (!$this->exists($normalizedPath)) {
            throw new Exception('Path "' . $path . '" "' . $normalizedPath . '" does not exist.');
        }

        $osPath = $this->pathPrefixer->prefixPath($normalizedPath);

        if (is_link($osPath)) {
            return true;
        }

        if (realpath($osPath) !== $osPath) {
            return true;
        }

        $workingDir = $this->normalizePath($this->workingDir);

        return ! str_starts_with($normalizedPath, $workingDir);
    }

    /**
     * Does the subDir path start with the dir path?
     */
    public function isSubDirOf(string $dir, string $subDir): bool
    {
        return str_starts_with(
            $this->normalizePath($subDir),
            $this->normalizePath($dir)
        );
    }

    public function normalizePath(string $path): string
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
        $normalizedPath = self::normalizeDirSeparator($path);
        $normalizedRoot = self::normalizeDirSeparator(self::getFsRoot($this->workingDir));

        if (str_starts_with(strtoupper($normalizedPath), $normalizedRoot)) {
            return self::normalizeDirSeparator($path, DIRECTORY_SEPARATOR);
        }

        $prefixed = $this->pathPrefixer->prefixPath($this->normalizePath($path));

        if ($this->flysystem instanceof ReadOnlyFileSystem) {
            return str_replace(':/', '://', $prefixed);
        }

        return self::normalizeDirSeparator($prefixed, DIRECTORY_SEPARATOR);
    }

    /**
     * @throws FilesystemException
     * @throws Exception
     */
    public function isDirectoryEmpty(string $dirPath): bool
    {
        if (!empty($this->listContents($dirPath)->toArray())) {
            return false;
        }

        $fsPath = $this->pathPrefixer->prefixPath($this->normalizePath($dirPath) . DIRECTORY_SEPARATOR . '*');
        $fsList = glob($fsPath);

        if (false === $fsList) {
            throw new Exception('glob() failed on ' . $fsPath);
        }

        return empty($fsList);
    }
}
