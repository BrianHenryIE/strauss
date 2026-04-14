<?php

namespace BrianHenryIE\Strauss\Helpers;

use League\Flysystem\FileAttributes;

/**
 * @see FlysystemBackCompatInterface
 */
trait FlysystemBackCompatTrait
{

    // Some version of Flysystem has:
    // directoryExists
    public function directoryExists(string $location): bool
    {
        if (method_exists($this, 'normalizePath')) {
            $location = $this->normalizePath($location);
        }

        /**
         * Use `self::class` here to check the parent of the current class, not necessarily the parent of the class
         * which was called.
         */
//        if (get_parent_class(self::class) && method_exists(get_parent_class(self::class), 'directoryExists')) {
//            return parent::directoryExists($location);
//        }

        if (property_exists($this, 'flysystemAdapter') && method_exists($this->flysystemAdapter, 'directoryExists')) {
            return $this->flysystemAdapter->directoryExists($location);
        }

        if (property_exists($this, 'filesystem') && method_exists($this->filesystem, 'directoryExists')) {
            return $this->filesystem->directoryExists($location);
        }

        $parentDirectoryContents = $this->listContents(dirname($location), false);
        /** @var FileAttributes $entry */
        foreach ($parentDirectoryContents as $entry) {
            if ($entry->path() == $location) {
                return $entry->isDir();
            }
        }

        // symlinks.
        if (false !== realpath($this->pathPrefixer->prefixPath($location))
            && is_dir($this->pathPrefixer->prefixPath($location))) {
            return true;
        }

        return false;
    }

    // Some version of Flysystem has:
    // has
    public function has(string $location): bool
    {
        if (get_parent_class(self::class) && method_exists(get_parent_class(self::class), 'has')) {
            return parent::has($location);
        }
        return $this->fileExists($location) || $this->directoryExists($location);
    }
}
