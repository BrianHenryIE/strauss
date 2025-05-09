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
        /**
         * Use `self::class` here to check the parent of the current class, not necessarily the parent of the class
         * which was called.
         */
        if (get_parent_class(self::class) && method_exists(get_parent_class(self::class), 'directoryExists')) {
             return parent::directoryExists($location);
        }

        $normalizer = $this->getNormalizer();
        $normalizedLocation = $normalizer->normalizePath($location);

        $parentDirectoryContents = $this->listContents(dirname($normalizedLocation), false);
        /** @var FileAttributes $entry */
        foreach ($parentDirectoryContents as $entry) {
            if ($entry->path() == $normalizedLocation) {
                return $entry->isDir();
            }
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
