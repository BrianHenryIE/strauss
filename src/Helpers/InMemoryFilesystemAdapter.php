<?php

namespace BrianHenryIE\Strauss\Helpers;

use League\Flysystem\FileAttributes;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter as LeagueInMemoryFilesystemAdapter;
use League\Flysystem\UnableToRetrieveMetadata;

class InMemoryFilesystemAdapter extends LeagueInMemoryFilesystemAdapter
{

    public function visibility(string $path): FileAttributes
    {
        if (!$this->fileExists($path)) {
            // Assume it is a directory.

//            Maybe check does the directory exist.
//            $parentDirContents = (array) $this->listContents(dirname($path), false);
//            throw UnableToRetrieveMetadata::visibility($path, 'file does not exist');

            return new FileAttributes($path, null, 'public');
        }


        return parent::visibility($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        if (!$this->fileExists($path)) {
            // Assume it is a directory
            return new FileAttributes($path, null, null, 0);
        }

        return parent::lastModified($path);
    }
}
