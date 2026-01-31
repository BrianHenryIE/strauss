<?php
/**
 * This class extends Flysystem's Filesystem class to add some additional functionality, particularly around
 * symlinks which are not supported by Flysystem.
 *
 * @see https://github.com/thephpleague/flysystem/issues/599
 */

namespace BrianHenryIE\Strauss\Helpers;

use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Pipeline\Autoload;
use BrianHenryIE\Strauss\Pipeline\Autoload\DumpAutoload;
use BrianHenryIE\Strauss\Pipeline\Cleanup\InstalledJson;
use BrianHenryIE\Strauss\Pipeline\DependenciesEnumerator;
use Composer\Factory;
use Elazar\Flystream\StripProtocolPathNormalizer;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\FilesystemReader;
use League\Flysystem\PathNormalizer;
use BrianHenryIE\Strauss\Helpers\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\WhitespacePathNormalizer;

class FileSystem extends \League\Flysystem\Filesystem implements FlysystemBackCompatTraitInterface
{
    use FlysystemBackCompatTrait;

    protected FilesystemOperator $flysystem;

    protected PathNormalizer $normalizer;

    protected PathPrefixer $pathPrefixer;
    /**
     * @var ReadOnlyFileSystem|SymlinkProtectFilesystemAdapter|FilesystemAdapter
     */
    protected $flysystemAdapter;

    /**
     * For calculating project-relative file paths.
     *
     * @var false|string
     */
    protected string $workingDir;

    /**
     * @param ReadOnlyFileSystem|SymlinkProtectFilesystemAdapter $adapter
     * @param array $config
     * @param PathNormalizer|null $pathNormalizer
     */
    public function __construct(
        FilesystemAdapter $adapter,
        array $config = [],
        PathNormalizer $pathNormalizer = null,
        PathPrefixer $pathPrefixer = null,
        ?string $workingDir = null
    ) {
        parent::__construct($adapter, $config, $pathNormalizer);

        // Parent is private.
        $this->normalizer = $pathNormalizer ?? new WhitespacePathNormalizer();
        $this->flysystemAdapter = $adapter;

        $this->pathPrefixer = $pathPrefixer ?? new PathPrefixer($this->getFileSystemRoot());

        $this->workingDir = $workingDir ?? getcwd();
    }

    // TODO: or `mem://`
    protected function getFileSystemRoot(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? substr(getcwd(), 0, 3) : '/';
    }

    public function getAdapter(): FilesystemAdapter
    {
        return $this->flysystemAdapter;
    }

    /**
     * Normalize directory separators to forward slashes.
     *
     * PHP native functions (realpath, getcwd, dirname) return backslashes on Windows,
     * but Flysystem always uses forward slashes. This method ensures consistency.
     *
     * Accepts null to preserve original str_replace() behavior where null is treated as empty string.
     */
    public static function normalizeDirSeparator(?string $path): string
    {
        return str_replace('\\', '/', $path ?? '');
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
     * Check is a file under a symlinked path.
     *
     * Check does the filepath point to a file outside the working directory.
     * If `realpath()` fails to resolve the path, assume it's a symlink.
     */
    public function isSymlinkedFile(FileBase $file): bool
    {
        $adapter = $this->flysystemAdapter;

        if ($adapter instanceof ReadOnlyFileSystem) {
            $adapter = $adapter->getAdapter();
        }

        if ($adapter instanceof SymlinkProtectFilesystemAdapter) {
            return $adapter->isSymlinked($file->getSourcePath());
        }

//        $realpath = realpath($file->getSourcePath());
//
//        return ! $realpath || ! str_starts_with($realpath, $this->workingDir);

        throw new \Exception('Cannot determine symbolic link for files');
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

    public function prefixPath(string $packageComposerFile): string
    {
        if ($this->flysystemAdapter instanceof InMemoryFilesystemAdapter) {
            return $this->pathPrefixer->prefixPath($packageComposerFile);
        }

        if (!($this->flysystemAdapter instanceof ReadOnlyFileSystem)
                || $this->shouldUseFilesystemPrefix()) {
            $prefixer = new PathPrefixer(
                $this->getFileSystemRoot(),
                DIRECTORY_SEPARATOR,
            );

            return $prefixer->prefixPath($packageComposerFile);
        }

        return $this->pathPrefixer->prefixPath($packageComposerFile);
    }

    /**
     * Temporary solution.
     *
     * when a filepath is being passed to a class that cannot use stream wrappers, always just pass the filesystem path
     */
    private function shouldUseFilesystemPrefix(): bool
    {
        $callingMethod = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 3)[2];

        $enableFor = [
            DependenciesEnumerator::class => ['recursiveGetAllDependencies'],
            DumpAutoload::class => null,
            Autoload::class => null,
        ];

        if (isset($enableFor[$callingMethod['class']])
            &&
           (
                is_null($enableFor[$callingMethod['class']])
                ||
                in_array($callingMethod['function'], $enableFor[$callingMethod['class']])
           )
        ) {
            return true;
        }

        return false;
    }

    /**
     * @see Factory::createComposer() uses {@see realpath()} which doesn't work with stream wrappers.
     */
    public function prefixPathRealpath(string $packageComposerFile): string
    {
        return $this->pathPrefixer->prefixPath($packageComposerFile);
    }
}
