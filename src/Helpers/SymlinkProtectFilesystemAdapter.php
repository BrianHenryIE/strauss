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
 * TODO: Aside: Is it possible to "implement" an interface in PHP that just uses `__call` and the
 * class acts a proxy to another class, and only implements the couple of methods it
 * cares about?
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
 * * Nothing. Extend this class and add info logs if required. (TODO)
 *
 * Outcome of write/modify operation on non-symlinked files:
 * * debug log
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
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\PathNormalizer;
use League\Flysystem\PathPrefixer;
use League\Flysystem\WhitespacePathNormalizer;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class SymlinkProtectFilesystemAdapter implements FilesystemAdapter, FlysystemBackCompatTraitInterface
{
    use ProxyFlysystemAdapterTrait;
    use FlysystemBackCompatTrait;
    use LoggerAwareTrait;

    protected PathNormalizer $normalizer;

    /**
     * Record of discovered symlink paths
     * * allows faster lookup in future
     * * provides list of symlinked paths for "did we encounter a symlink" checks
     *
     * @var array<string, string> Array of flysystem relative paths : full filesystem paths.
     */
    protected array $symlinkPaths = [];

    /**
     * Converts flysystem relative paths to filesystem absolute paths.
     */
    protected PathPrefixer $pathPrefixer;

    /**
     * TODO: If a symlinked file is "deleted", keep a record of it and prevent any future access to it.
     *
     * @var array<string, string> Array of flysystem relative paths : flysystem relative paths.
     */
    protected array $deletedPaths = [];

    public function __construct(
        FilesystemAdapter $parentFilesystem,
        PathPrefixer $pathPrefixer,
        PathNormalizer $pathNormalizer = null,
        LoggerInterface $logger = null
    ) {
        $this->setLogger($logger ?? new NullLogger());

        $this->setProxyFilesystemAdapter($parentFilesystem);

        $this->pathPrefixer = $pathPrefixer;
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
        foreach ($this->symlinkPaths as $flysystemPath => $symlinkPath) {
            if (str_starts_with($path, $flysystemPath)) {
                return $symlinkPath;
            }
        }

        $absolutePath = $this->pathPrefixer->prefixPath($path);

        $parts = explode('/', $path);
        do {
            $partsPath = implode('/', $parts);
            $absoluteParentDir = $this->pathPrefixer->prefixPath($partsPath);
            if (is_link($absoluteParentDir)) {
                $this->symlinkPaths[$partsPath] = $absoluteParentDir;
                return $absoluteParentDir;
            }
            array_pop($parts);
        } while (count($parts) > 0);

        $realpath = realpath($absolutePath);

        /**
         * If realpath() returns false, the file may be in an in-memory filesystem.
         *
         * @see https://github.com/php/php-src/issues/12118
         */
        if ($realpath === false) {
            return null;
        }

        if ($absolutePath === $realpath) {
            return null;
        }

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

        return !method_exists($this->proxyFilesystemAdapter, 'directoryExists')
            ? !$this->proxyFilesystemAdapter->fileExists($path)
            : !$this->proxyFilesystemAdapter->fileExists($path) && !$this->proxyFilesystemAdapter->directoryExists($path);
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
            call_user_func_array([$this->proxyFilesystemAdapter,__FUNCTION__], func_get_args());
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
            call_user_func_array([$this->proxyFilesystemAdapter, __FUNCTION__], func_get_args());
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
            call_user_func_array([$this->proxyFilesystemAdapter, __FUNCTION__], func_get_args());
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
            call_user_func_array([$this->proxyFilesystemAdapter, __FUNCTION__], func_get_args());
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
            call_user_func_array([$this->proxyFilesystemAdapter, __FUNCTION__], func_get_args());
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
            call_user_func_array([$this->proxyFilesystemAdapter, __FUNCTION__], func_get_args());
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
            call_user_func_array([$this->proxyFilesystemAdapter, __FUNCTION__], func_get_args());
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
            call_user_func_array([$this->proxyFilesystemAdapter, __FUNCTION__], func_get_args());
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

    /**
     * @see FlysystemBackCompatTrait::directoryExists()
     */
    public function getNormalizer(): PathNormalizer
    {
        return $this->normalizer;
    }
}
