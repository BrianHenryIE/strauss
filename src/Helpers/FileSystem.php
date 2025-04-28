<?php
/**
 * This class extends Flysystem's Filesystem class to add some additional functionality, particularly around
 * symlinks which are not supported by Flysystem.
 *
 * @see https://github.com/thephpleague/flysystem/issues/599
 */

namespace BrianHenryIE\Strauss\Helpers;

use BrianHenryIE\Strauss\Files\FileBase;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemReader;
use League\Flysystem\PathNormalizer;
use League\Flysystem\PathPrefixer;
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
     * @param ReadOnlyFileSystem|SymlinkProtectFilesystemAdapter $adapter
     * @param array $config
     * @param PathNormalizer|null $pathNormalizer
     */
    public function __construct(
        FilesystemAdapter $adapter,
        array $config = [],
        PathNormalizer $pathNormalizer = null,
        PathPrefixer $pathPrefixer = null
    ) {
        parent::__construct($adapter, $config, $pathNormalizer);

        // Parent is private.
        $this->normalizer = $pathNormalizer ?? new WhitespacePathNormalizer();
        $this->flysystemAdapter = $adapter;

        $this->pathPrefixer = $pathPrefixer ?? new PathPrefixer($this->getFileSystemRoot());
    }

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
     * Check is a file under a symlinked path.
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

    public function pathPrefix(string $packageComposerFile): string
    {
        return $this->pathPrefixer->prefixPath($packageComposerFile);
    }
}
