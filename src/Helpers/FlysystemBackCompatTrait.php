<?php

namespace BrianHenryIE\Strauss\Helpers;

use Elazar\Flystream\StripProtocolPathNormalizer;
use League\Flysystem\FileAttributes;
use League\Flysystem\WhitespacePathNormalizer;

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
        if (method_exists(get_parent_class(self::class), 'directoryExists')) {
             return parent::directoryExists($location);
        }

        $normalizer = new WhitespacePathNormalizer();
        $normalizer = new StripProtocolPathNormalizer(['mem'], $normalizer);
        $location = $normalizer->normalizePath($location);

        $parentDirectoryContents = $this->listContents(dirname($location), false);
        /** @var FileAttributes $entry */
        foreach ($parentDirectoryContents as $entry) {
            if ($entry->path() == $location) {
                return $entry->isDir();
            }
        }

        return false;
    }

    // Some version of Flysystem has:
    // has
    public function has(string $location): bool
    {
        if (method_exists(get_parent_class(self::class), 'has')) {
            return parent::has($location);
        }
        return $this->fileExists($location) || $this->directoryExists($location);
    }
}
