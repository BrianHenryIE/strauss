<?php
/**
 * `FilesystemAdapter` interface v3 introduced `::directoryExists()`.
 *
 * v2:
 * @see https://github.com/thephpleague/flysystem/blob/2.x/src/FilesystemAdapter.php
 * Interface:
 * @see https://github.com/thephpleague/flysystem/blob/3.x/src/FilesystemAdapter.php
 * Implementations:
 * @see https://github.com/thephpleague/flysystem/blob/3.x/src/Filesystem.php#L41-L44
 * @see https://github.com/thephpleague/flysystem/blob/3.x/src/Local/LocalFilesystemAdapter.php#L346-L351
 */

namespace BrianHenryIE\Strauss\Helpers;

use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;

/**
 * @mixin FlysystemAdapterBackCompatTraitInterface
 *
 * @method string normalizePath($location)
 */
trait FlysystemAdapterBackCompatTrait
{
    /**
     * @see FilesystemAdapter::directoryExists()
     * @param string $path
     *
     * @return bool
     * @throws FilesystemException
     */
    public function directoryExists(string $path): bool
    {
        /**
         * Use `self::class` here to check the parent of the current class, not necessarily the parent of the class
         * which was called.
         *
         * @phpstan-ignore booleanAnd.leftAlwaysTrue
         */
        if (get_parent_class(self::class) && method_exists(get_parent_class(self::class), 'directoryExists')) {
            /** @phpstan-ignore staticMethod.notFound  */
            return parent::directoryExists($path);
        }

        return $this->directoryExistsImplementation($path);
    }

    /**
     * @throws FilesystemException
     */
    protected function directoryExistsImplementation(string $path): bool
    {
        $path = $this->normalizePath($path);

        $parentDir = dirname($path);
        $parentDir = $this->normalizePath($parentDir);
        $parentDir = $parentDir === '.' ? '/' : $parentDir;

        // Root dir must exist!
        if ($parentDir === $path) {
            return true;
        }

        $parentDirectoryContents = $this->listContents($parentDir, false);
        /** @var FileAttributes $entry */
        foreach ($parentDirectoryContents as $entry) {
            if ($entry->path() == $path) {
                return $entry->isDir();
            }
        }

        // Symlinks.
        // TODO: This should be moved into its own adapter.
        if (property_exists($this, 'pathPrefixer')) {
            if (false !== realpath($this->pathPrefixer->prefixPath($path))
                && is_dir($this->pathPrefixer->prefixPath($path))) {
                return true;
            }
        }

        return false;
    }
}
