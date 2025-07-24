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
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemReader;
use League\Flysystem\PathNormalizer;
use BrianHenryIE\Strauss\Helpers\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\WhitespacePathNormalizer;

class FileSystem extends \League\Flysystem\Filesystem implements FlysystemBackCompatTraitInterface
{
    use FlysystemBackCompatTrait;

    protected PathNormalizer $normalizer;

    protected PathPrefixer $pathPrefixer;
    /**
     * @var ReadOnlyFileSystem|SymlinkProtectFilesystemAdapter|FilesystemAdapter
     */
    protected $flysystemAdapter;
    /**
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

            $paths = array_map(fn($file) => $file->path(), $fileAttributesArray);

            $files = array_merge($files, $paths);
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

    public function exists(string $location): bool
    {
        return $this->fileExists($location) || $this->directoryExists($location);
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
