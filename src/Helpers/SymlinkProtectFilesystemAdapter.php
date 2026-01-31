<?php
/**
 * The idea is to prevent write/delete operations to symlinked files.
 *
 * This class proxies filesystem operations to another Flysystem adapter, but checks if the path is
 * symlinked and prevents write/delete operations to files/directories inside a symlinked directory.
 * Unlinks symlinks if delete is called on them.
 *
 * Read operations act normally, write operations log warnings and errors.
 *
 * Outcome of trying to delete a file inside a symlink:
 * * logs an error (FlySystem FileSystemAdapter probably throws an exception)
 * * prevents future access to the file (as though it really were deleted)
 *
 * Outcome of trying to write a file inside a symlink:
 * * logs a warning (FlySystem FileSystemAdapter probably throws an exception)
 *
 * Outcome of trying to delete a symlink:
 * * logs a notice (FlySystem FileSystemAdapter probably throws an exception)
 * * unlinks the target (what we want to do)
 * * we must be careful to not delete the target of the symlink
 *
 * Outcome of read operation on symlinked files:
 * * Debug log every time a symlinked file is read
 *
 * Outcome of write/modify operation on non-symlinked files:
 * * nothing
 *
 * Info log the first time each symlink is seen
 *
 * Your implementation of LoggerInterface can decide what to do with the log messages, e.g.
 * throw an exception on error, or just log them with an instruction on how to remedy the issue.
 *
 * TODO: Should this just extend LocalFilesystemAdapter since it only applies to local filesystems?
 *
 * @see \League\Flysystem\SymbolicLinkEncountered
 * @see \League\Flysystem\Local\LocalFilesystemAdapter::SKIP_LINKS
 * @see \League\Flysystem\Local\LocalFilesystemAdapter::DISALLOW_LINKS
 * @see \League\Flysystem\Local\LocalFilesystemAdapter::$linkHandling
 * @see \League\Flysystem\Local\LocalFilesystemAdapter::listContents()
 */

namespace BrianHenryIE\Strauss\Helpers;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PathNormalizer;
use BrianHenryIE\Strauss\Helpers\PathPrefixer;
use League\Flysystem\UnixVisibility\VisibilityConverter;
use League\Flysystem\WhitespacePathNormalizer;
use League\MimeTypeDetection\MimeTypeDetector;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class SymlinkProtectFilesystemAdapter extends LocalFilesystemAdapter implements FlysystemBackCompatTraitInterface
{
    use FlysystemBackCompatTrait;
    use LoggerAwareTrait;

    protected PathNormalizer $normalizer;

    /**
     * Converts flysystem relative paths to filesystem absolute paths.
     */
    protected PathPrefixer $pathPrefixer;

    /**
     * Record of discovered symlink paths
     * * allows faster lookup in future
     * * provides list of symlinked paths for "did we encounter a symlink" checks
     *
     * @var array<string, string> Array of flysystem relative paths : target path.
     */
    protected array $symlinkPaths = [];

    /**
     * Record of non-symlinked paths to avoid running is_link repeatedly.
     *
     * I.e. no need to `/check/every/level/of/this/when` we partial path has been checked before.
     */
    protected array $nonSymlinkPaths = [];

    /**
     * Record of all files already checked.
     *
     * @var array<string, string|null> Array of flysystem relative paths : flysystem relative path to symlink, or null.
     */
    protected array $checkedCache = [];

    /**
     * TODO: If a symlinked file is "deleted", keep a record of it and prevent any future access to it.
     * TODO: If a symlinked directory is "deleted" forbid access to any files inside it.
     *
     * @var array<string, string> Array of flysystem relative paths : flysystem relative paths.
     */
    protected array $deletedPaths = [];

    public function __construct(
        ?PathNormalizer $pathNormalizer = null,
        ?PathPrefixer $pathPrefixer = null,
        ?LoggerInterface $logger = null,
        ?VisibilityConverter $visibility = null,
        int $writeFlags = LOCK_EX,
        int $linkHandling = LocalFilesystemAdapter::SKIP_LINKS,
        ?MimeTypeDetector $mimeTypeDetector = null
    ) {
        $location = $this->isWindowsOS() ? substr(getcwd(), 0, 3) : '/';

        parent::__construct($location, $visibility, $writeFlags, $linkHandling, $mimeTypeDetector);

        $this->setLogger($logger ?? new NullLogger());

        $this->pathPrefixer = $pathPrefixer ?? new PathPrefixer($location, DIRECTORY_SEPARATOR);
        $this->normalizer = $pathNormalizer ?? new WhitespacePathNormalizer();
    }

    /**
     * Check is a file within a symlinked directory.
     *
     * Realpath expects a true file to exist on the filesystem.
     * I.e. using flysystem with a root path which is relative to the true filesystem, will never work.
     *
     * @see \SplFileInfo::isLink()
     *
     * @see https://github.com/php/php-src/issues/12118
     *
     * @return ?string The filesystem path to the symlink, or null if not a symlink.
     */
    protected function getSymlink(string $path): ?string
    {
        if (isset($this->checkedCache[$path])) {
            return $this->checkedCache[$path];
        }

        foreach ($this->symlinkPaths as $flysystemPath => $symlinkPath) {
            if (str_starts_with($path, $flysystemPath)) {
                $target = $this->normalizer->normalizePath($symlinkPath . str_replace($flysystemPath, '', $path));
                $this->checkedCache[$path] = $target;
                return $target;
            }
        }

        $absolutePath = $this->pathPrefixer->prefixPath($path);

        $prefixParts = explode('/', $path);
        $checkedPaths = [];
        do {
            $partsPath = implode('/', $prefixParts);
            $absoluteParentDir = $this->pathPrefixer->prefixPath($partsPath);
            if (isset($this->nonSymlinkPaths[$partsPath])) {
                return null;
            }
            if (is_link($absoluteParentDir)) {
                $this->recordSymlink($path, $absoluteParentDir);
                $this->nonSymlinkPaths = array_merge($this->nonSymlinkPaths, $checkedPaths);
                return $absoluteParentDir;
            } else {
                $checkedPaths[$partsPath] = $absoluteParentDir;
            }
            array_pop($prefixParts);
        } while (count($prefixParts) > 0);

        $realpath = realpath($absolutePath);

        /**
         * If realpath() returns false, the file may be in an in-memory filesystem.
         * Or maybe the file really does not exist.
         *
         * @see https://github.com/php/php-src/issues/12118
         */
        if ($realpath === false
            || $absolutePath === $realpath) {
            $this->nonSymlinkPaths = array_merge($this->nonSymlinkPaths, $checkedPaths);

            $this->checkedCache[$path] = null;

            return null;
        }

        $this->recordSymlink($path, $absolutePath);

        return $absolutePath;
    }

    /**
     * Given the path to a file or folder, is it inside a symlinked directory?
     */
    public function isSymlinked(string $path): bool
    {
        $path = $this->normalizer->normalizePath($path);

        return (bool) $this->getSymlink($path);
    }

    protected function recordSymlink(string $path, string $symlinkSource): void
    {
        $symlinkTarget = realpath($symlinkSource);
        $symlinkSource = $this->normalizer->normalizePath($symlinkSource);
        $symlinkTarget = $this->normalizer->normalizePath($symlinkTarget);

        $fileTarget = $this->normalizer->normalizePath($symlinkTarget . str_replace($symlinkSource, '', $path));
        $this->checkedCache[$path] = $fileTarget;

        if (isset($this->symlinkPaths[$symlinkSource])) {
            return;
        }

        $this->symlinkPaths[$symlinkSource] = $symlinkTarget;

        $this->logger->info(
            "New symlink found at {$symlinkSource} target {$symlinkTarget}.",
            [
                'source' => $symlinkSource,
                'target' => $symlinkTarget,
            ]
        );
    }

    public function getSymlinks(): array
    {
        return $this->symlinkPaths;
    }

    /**
     * Deleting a symlink is different on Windows and Linux.
     *
     * `unlink()` will not work on Windows. `rmdir()` will not work if there are files in the directory.
     * "On windows, take care that `is_link()` returns false for Junctions."
     *
     * @see https://www.php.net/manual/en/function.is-link.php#113263
     * @see https://stackoverflow.com/a/18262809/336146
     * @throws FilesystemException
     */
    protected function removeSymlink(string $path): bool
    {
        $fullPath = $this->pathPrefixer->prefixPath($path);

        $this->logger->notice('Deleting symlink at ' . $fullPath . ' (points to ' . realpath($fullPath) . ')');

        if ($this->isWindowsOS()) {
            rmdir($fullPath);
        } else {
            unlink($fullPath);
        }

        return !method_exists($this, 'directoryExists')
            ? !$this->fileExists($path)
            : !$this->fileExists($path) && !$this->directoryExists($path);
    }


    /**
     * Check are we running on Windows, whose symlink behaviour differs.
     *
     * TODO: Consider using `PHP_OS_FAMILY` instead.
     *
     * @see https://www.php.net/manual/en/reserved.constants.php#constant.php-os
     */
    protected function isWindowsOS(): bool
    {
        return false !== strpos('WIN', constant('PHP_OS'));
    }

    /**
     * @see FlysystemBackCompatTrait::directoryExists()
     */
    public function getNormalizer(): PathNormalizer
    {
        return $this->normalizer;
    }

    /**
     * @see FilesystemAdapter::write()
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $path = $this->normalizer->normalizePath($path);

        $symlink = $this->getSymlink($path);

        if (!$symlink) {
            $this->logger->debug("Writing non-symlinked file at {$path}", [
                'method' => __METHOD__,
                'args' => func_get_args()
            ]);
            parent::write($path, $contents, $config);
            return;
        }

        $this->logger->warning(
            'File is/is under a symlinked path.',
            [
                'symlink' => $symlink,
                'method' => __METHOD__,
                'args' =>func_get_args()
            ]
        );
    }

    /**
     * @see FilesystemAdapter::writeStream()
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $path = $this->normalizer->normalizePath($path);

        $symlink = $this->getSymlink($path);

        if (!$symlink) {
            $this->logger->debug("Writing stream for non-symlinked file at {$path}", [
                'method' => __METHOD__,
                'args' => func_get_args()
            ]);
            parent::writeStream($path, $contents, $config);
            return;
        }

        $this->logger->warning(
            'File is/is under a symlinked path.',
            [
                'symlink' => $symlink,
                'method' => __METHOD__,
                'args' =>func_get_args()
            ]
        );
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $path = $this->normalizer->normalizePath($path);

        $symlink = $this->getSymlink($path);

        if (!$symlink) {
            $this->logger->debug("Setting visibility for non-symlinked file at {$path}", [
                'method' => __METHOD__,
                'args' => func_get_args()
            ]);
            parent::setVisibility($path, $visibility);
            return;
        }

        $this->logger->warning(
            'File is/is under a symlinked path.',
            [
                'symlink' => $symlink,
                'method' => __METHOD__,
                'args' =>func_get_args()
            ]
        );
    }

    public function delete(string $path): void
    {
        $path = $this->normalizer->normalizePath($path);

        $symlink = $this->getSymlink($path);

        if (!$symlink) {
            $this->logger->debug("Deleting non-symlinked file at {$path}", [
                'method' => __METHOD__,
                'args' => func_get_args()
            ]);
            parent::delete($path);
            return;
        }

        $this->deletedPaths[$path] = $symlink;

        $fullPath = $this->pathPrefixer->prefixPath($path);
        if ($symlink === $fullPath) {
            $didRemove = $this->removeSymlink($path);
            $this->logger->notice(
                'File is a symlinked path, removing.',
                [
                    'didRemove' => $didRemove,
                    'symlink' => $symlink,
                    'method' => __METHOD__,
                    'args' =>func_get_args()
                ]
            );
            return;
        }

        $this->logger->error(
            'File is under a symlinked path.',
            [
                'symlink' => $symlink,
                'method' => __METHOD__,
                'args' =>func_get_args()
            ]
        );
    }

    public function deleteDirectory(string $path): void
    {
        $path = $this->normalizer->normalizePath($path);

        $symlink = $this->getSymlink($path);

        if (!$symlink) {
            $this->logger->debug("Deleting non-symlinked directory at {$path}", [
                'method' => __METHOD__,
                'args' => func_get_args()
            ]);
            parent::deleteDirectory($path);
            return;
        }

        $this->deletedPaths[$path] = $symlink;

        $fullPath = $this->pathPrefixer->prefixPath($path);
        if ($symlink === $fullPath) {
            $didRemove = $this->removeSymlink($path);
            $this->logger->notice(
                'Directory is a symlinked path, removing.',
                [
                    'didRemove' => $didRemove,
                    'symlink' => $symlink,
                    'method' => __METHOD__,
                    'args' =>func_get_args()
                ]
            );
            return;
        }

        $this->logger->error(
            'Directory is under a symlinked path.',
            [
                'symlink' => $symlink,
                'method' => __METHOD__,
                'args' =>func_get_args()
            ]
        );
    }

    public function createDirectory(string $path, Config $config): void
    {
        $path = $this->normalizer->normalizePath($path);

        $symlink = $this->getSymlink($path);

        if (!$symlink) {
            $this->logger->debug("Creating directory at non-symlinked path {$path}", [
                'method' => __METHOD__,
                'args' => func_get_args()
            ]);
            parent::createDirectory($path, $config);
            return;
        }

        $this->logger->warning(
            'Attempted to create directory under a symlinked path.',
            [
                'symlink' => $symlink,
                'method' => __METHOD__,
                'args' =>func_get_args()
            ]
        );
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $source = $this->normalizer->normalizePath($source);
        $destination = $this->normalizer->normalizePath($destination);

        $sourceSymlink = $this->getSymlink($source);
        $destinationSymlink = $this->getSymlink($destination);

        if (!$sourceSymlink && !$destinationSymlink) {
            $this->logger->debug("Creating directory at non-symlinked path {$path}", [
                'method' => __METHOD__,
                'args' => func_get_args()
            ]);
            parent::move($source, $destination, $config);
            return;
        }

        $this->logger->warning(
            'Attempted to move file/directory under a symlinked path.',
            [
                'symlink' => $sourceSymlink || $destinationSymlink,
                'method' => __METHOD__,
                'args' =>func_get_args()
            ]
        );
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $source = $this->normalizer->normalizePath($source);
        $destination = $this->normalizer->normalizePath($destination);

        $symlink = $this->getSymlink($destination);

        if (!$symlink) {
            $this->logger->debug("Copying file/dir at non-symlinked path {$destination}", [
                'method' => __METHOD__,
                'args' => func_get_args()
            ]);
            parent::copy($source, $destination, $config);
            return;
        }

        $this->logger->warning(
            'Attempted to move file/directory under a symlinked path.',
            [
                'symlink' => $symlink,
                'method' => __METHOD__,
                'args' => func_get_args()
            ]
        );
    }

    public function fileExists(string $path): bool
    {
        $path = $this->normalizer->normalizePath($path);
        $symlink = $this->getSymlink($path);
        if ($symlink) {
            $this->logger->debug("FileExists symlinked file at {$path} to target {$symlink}", [
                'source' => $path,
                'target' => $symlink,
                'method' => __METHOD__,
                'args' => func_get_args()
            ]);
        }

        return parent::fileExists($path);
    }

    public function read(string $path): string
    {
        $path = $this->normalizer->normalizePath($path);
        $symlink = $this->getSymlink($path);
        if ($symlink) {
            $this->logger->debug("Reading symlinked file at {$path} to target {$symlink}", [
                'source' => $path,
                'target' => $symlink,
                'method' => __METHOD__,
                'args' => func_get_args()
            ]);
        }

        return parent::read($path);
    }

    public function readStream(string $path)
    {
        $path = $this->normalizer->normalizePath($path);
        $symlink = $this->getSymlink($path);
        if ($symlink) {
            $this->logger->debug(__FUNCTION__ . " symlinked {$path} to {$symlink}", [
                'source' => $path,
                'target' => $symlink,
                'method' => __METHOD__,
                'args' => func_get_args()
            ]);
        }

        return parent::readStream($path);
    }

    public function visibility(string $path): FileAttributes
    {
        $path = $this->normalizer->normalizePath($path);
        $symlink = $this->getSymlink($path);
        if ($symlink) {
            $this->logger->debug(__FUNCTION__ . " symlinked {$path} to {$symlink}", [
                'source' => $path,
                'target' => $symlink,
                'method' => __METHOD__,
                'args' => func_get_args()
            ]);
        }

        return parent::visibility($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        $path = $this->normalizer->normalizePath($path);
        $symlink = $this->getSymlink($path);
        if ($symlink) {
            $this->logger->debug(__FUNCTION__ . " symlinked {$path} to {$symlink}", [
                'source' => $path,
                'target' => $symlink,
                'method' => __METHOD__,
                'args' => func_get_args()
            ]);
        }

        return parent::mimeType($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        $path = $this->normalizer->normalizePath($path);
        $symlink = $this->getSymlink($path);
        if ($symlink) {
            $this->logger->debug(__FUNCTION__ . " symlinked {$path} to {$symlink}", [
                'source' => $path,
                'target' => $symlink,
                'method' => __METHOD__,
                'args' => func_get_args()
            ]);
        }

        return parent::lastModified($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        $path = $this->normalizer->normalizePath($path);
        $symlink = $this->getSymlink($path);
        if ($symlink) {
            $this->logger->debug(__FUNCTION__ . " symlinked {$path} to {$symlink}", [
                'source' => $path,
                'target' => $symlink,
                'method' => __METHOD__,
                'args' => func_get_args()
            ]);
        }

        return parent::fileSize($path);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $path = $this->normalizer->normalizePath($path);
        $symlink = $this->getSymlink($path);
        if ($symlink) {
            $this->logger->debug(__FUNCTION__ . " symlinked {$path} to {$symlink}", [
                'source' => $path,
                'target' => $symlink,
                'method' => __METHOD__,
                'args' => func_get_args()
            ]);
        }

        return parent::listContents($path, $deep);
    }
}
