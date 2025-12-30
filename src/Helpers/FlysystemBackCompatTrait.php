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
        $normalizer = new WhitespacePathNormalizer();
        $normalizer = new StripProtocolPathNormalizer(['mem'], $normalizer);
        $location = $normalizer->normalizePath($location);

        if (method_exists($this->flysystem, 'directoryExists')) {
            return $this->flysystem->directoryExists($location);
        }

        $parentDirectoryContents = $this->listContents(dirname($location));
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
        if (method_exists($this->flysystem, 'has')) {
            return $this->flysystem->has($location);
        }
        return $this->fileExists($location) || $this->directoryExists($location);
    }
}
