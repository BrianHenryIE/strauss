<?php
/**
 * This class extends Flysystem's Filesystem class to add some additional functionality, particularly around
 * symlinks which are not supported by Flysystem.
 *
 * @see https://github.com/thephpleague/flysystem/issues/599
 */

namespace BrianHenryIE\Strauss\Helpers;

use Composer\Util\Platform;
use Elazar\Flystream\StripProtocolPathNormalizer;
use Exception;
use League\Flysystem\Config;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\FilesystemReader;
use League\Flysystem\PathNormalizer;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;

class FileSystem extends \League\Flysystem\Filesystem implements FlysystemBackCompatTraitInterface, PathNormalizer
{
    use FlysystemBackCompatTrait;

    protected FilesystemOperator $flysystem;

    protected PathNormalizer $normalizer;

    /**
     * @var PathPrefixer
     */
    protected $pathPrefixer;

    /**
     * @var ReadOnlyFileSystem|SymlinkProtectFilesystemAdapter|FilesystemAdapter
     */
    protected $flysystemAdapter;

    /**
     * For calculating absolute paths outside the flysystem.
     *
     * No trailing slash, except for root directories (e.g., '/' or 'C:/' or 'mem://').
     */
    protected string $localFsLocation;

    /**
     * For printing relative paths.
     */
    protected string $workingDir;

    /**
     * Private in parent class.
     */
    protected Config $config;

    /**
     * TODO: maybe restrict the constructor to only accept a LocalFilesystemAdapter.
     *
     * TODO: Check are any of these methods unused
     *
     * @param ReadOnlyFileSystem|SymlinkProtectFilesystemAdapter $adapter
     * @param array $config
     * @param PathNormalizer|null $pathNormalizer
     */
    public function __construct(
        FilesystemAdapter $adapter,
        array $config = [],
        ?PathNormalizer $pathNormalizer = null,
        $pathPrefixer = null,
        ?string $localFsLocation = null,
        ?string $workingDir = null
    ) {
        $localFsLocation        = $localFsLocation ?? self::getFsRoot(Platform::getcwd());
        $pathNormalizer         = $pathNormalizer ?? self::makePathNormalizer($localFsLocation);
        $pathPrefixer           = $pathPrefixer ?? new PathPrefixer(
            $localFsLocation,
            DIRECTORY_SEPARATOR
        );

        parent::__construct($adapter, $config, $pathNormalizer);

        $this->config = new Config($config);

        // Parent is private.
        $this->normalizer       = $pathNormalizer;
        $this->pathPrefixer     = $pathPrefixer;
        $this->flysystemAdapter = $adapter;
        $this->localFsLocation  = $localFsLocation;
        $this->workingDir       = $pathNormalizer->normalizePath($workingDir ?? $localFsLocation);
    }

    public static function getFsRoot(?string $path = null): ?string
    {
        if (1 === preg_match('#^([a-zA-Z]+:[\\/]|\/)#', $path ?? Platform::getcwd(), $output_array)) {
            return strtoupper($output_array[1]);
        }
        // Relative path.
        return '';
    }

    public static function makePathNormalizer(string $workingDir): PathNormalizer
    {
        return new StripProtocolPathNormalizer(
            [
                'mem',
            ],
            new StripFsRootPathNormalizer(
                [
                    str_replace('\\', '/', FileSystem::getFsRoot($workingDir)),
                    str_replace('/', '\\', FileSystem::getFsRoot($workingDir)),
                    FileSystem::getFsRoot(),
                    FileSystem::normalizeDirSeparator(FileSystem::getFsRoot()),
                    'c:\\',
                    'c:/',
                ]
            )
        );
    }

    public function getAdapter(): FilesystemAdapter
    {
        return $this->flysystemAdapter;
    }

    public function setAdapter(FilesystemAdapter $flysystemAdapter): void
    {
        $this->flysystemAdapter = $flysystemAdapter;
    }

    /**
     * Normalize directory separators to forward slashes.
     *
     * PHP native functions (realpath, getcwd, dirname) return backslashes on Windows,
     * but Flysystem always uses forward slashes. This method ensures consistency.
     */
    public static function normalizeDirSeparator($path, $slashTo = '/'): string
    {
        $slashFrom = $slashTo === '/' ? '\\' : '/';

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

            $paths = array_map(
                fn(StorageAttributes $attributes): string => $this->makeAbsolute($attributes->path()),
                $fileAttributesArray
            );

            if ($excludeDirectories) {
                $paths = array_filter($paths, fn($path) => !$this->directoryExists($path));
            }

            $files = array_merge($files, $paths);
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
        return $this->flysystemAdapter->fileExists(
            $this->normalizePath($location)
        );
    }

    public function read(string $location): string
    {
        return $this->flysystemAdapter->read(
            $this->normalizePath($location)
        );
    }

    public function readStream(string $location)
    {
        return $this->flysystemAdapter->readStream(
            $this->normalizePath($location)
        );
    }

    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        return parent::listContents(
            $this->normalizePath($location),
            $deep
        );
    }

    /**
     * @see \League\Flysystem\Filesystem::lastModified()
     */
    public function lastModified(string $path): int
    {
        return $this->flysystemAdapter->lastModified(
            $this->normalizePath($path)
        )->lastModified();
    }

    public function fileSize(string $path): int
    {
        return $this->flysystemAdapter->fileSize(
            $this->normalizePath($path)
        )->fileSize();
    }

    public function mimeType(string $path): string
    {
        return $this->flysystemAdapter->mimeType(
            $this->normalizePath($path)
        )->mimeType();
    }

    /**
     * @see \League\Flysystem\Filesystem::visibility()
     */
    public function visibility(string $path): string
    {
        return $this->flysystemAdapter->visibility(
            $this->normalizePath($path)
        )->visibility();
    }

    /**
     * @param string $location
     * @param string $contents
     * @param array{visibility?:string} $config
     *
     * @return void
     * @throws FilesystemException
     */
    public function write(string $location, $contents, array $config = []): void
    {
        $this->flysystemAdapter->write(
            $this->normalizePath($location),
            $contents,
            $this->config->extend($config)
        );
    }

    /**
     * @param array{visibility?:string} $config
     * @throws FilesystemException
     */
    public function writeStream(string $location, $contents, $config = []): void
    {
        $this->rewindStream($contents);
        $this->flysystemAdapter->writeStream(
            $this->normalizePath($location),
            $contents,
            $this->config->extend($config)
        );
//        fclose($contents);
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


    public function setVisibility(string $path, string $visibility): void
    {
        $this->flysystemAdapter->setVisibility(
            $this->normalizer->normalizePath($path),
            $visibility
        );
    }

    public function delete(string $location): void
    {
        $this->flysystemAdapter->delete(
            $this->normalizer->normalizePath($location)
        );
    }

    public function deleteDirectory(string $location): void
    {
        $this->flysystemAdapter->deleteDirectory(
            $this->normalizePath($location)
        );
    }

    /**
     * @param array{visibility?:string} $config
     * @throws FilesystemException
     */
    public function createDirectory(string $location, $config = []): void
    {
        $this->flysystemAdapter->createDirectory(
            $this->normalizePath($location),
            $this->config->extend($config)
        );
    }

    /**
     * @param array{visibility?:string} $config
     * @throws FilesystemException
     */
    public function move(string $source, string $destination, $config = []): void
    {
        $this->flysystemAdapter->move(
            $this->normalizePath($source),
            $this->normalizePath($destination),
            $this->config->extend($config)
        );
    }

    /**
     * @param array{visibility?:string} $config
     * @throws FilesystemException
     */
    public function copy(string $source, string $destination, $config = []): void
    {
        $this->flysystemAdapter->copy(
            $this->normalizePath($source),
            $this->normalizePath($destination),
            $this->config->extend($config)
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
     * Check is a file under a symlinked path.
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

        $workingDir = $this->normalizePath($this->localFsLocation);

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
        $fsRoot = self::getFsRoot($this->localFsLocation);

        // If this is already prefixed with the drive(fs) root.
        if (stripos($path, $fsRoot) === 0 || stripos($path, self::normalizeDirSeparator($fsRoot)) === 0) {
            return $path;
        }

        $normalizedPath = $this->normalizePath($path);

        if (strtolower(self::getFsRoot($this->localFsLocation)) === strtolower(self::getFsRoot($normalizedPath))) {
            return $path;
        }

        $normalizedRoot = self::normalizeDirSeparator(self::getFsRoot($this->localFsLocation));

        if (str_starts_with(strtoupper($normalizedPath), $normalizedRoot)) {
            return self::normalizeDirSeparator($path, DIRECTORY_SEPARATOR);
        }

//        if ($this->getAdapter() instanceof InMemoryFilesystemAdapter || $this->getAdapter() instanceof ReadOnlyFileSystem) {
        if (\Composer\Util\Filesystem::isStreamWrapperPath($this->localFsLocation)) {
            return $this->localFsLocation . $path;
        }

        $prefixed = $this->pathPrefixer->prefixPath($this->normalizePath($path));

//        if ($this->flysystemAdapter instanceof ReadOnlyFileSystem) {
//            return str_replace(':/', '://', $prefixed);
//        }

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

    public function getNormalizer(): PathNormalizer
    {
        return $this->normalizer;
    }

    public function setLocalFsLocation(string $string): void
    {
        $this->localFsLocation = $string;
    }
}
