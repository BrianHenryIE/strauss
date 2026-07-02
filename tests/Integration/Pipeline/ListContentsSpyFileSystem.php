<?php

namespace BrianHenryIE\Strauss\Tests\Integration\Pipeline;

use BrianHenryIE\Strauss\Helpers\FileSystem;
use League\Flysystem\DirectoryListing;

/**
 * A {@see FileSystem} which records every `listContents()` call so a test can assert which directories
 * were walked, and whether the walk was deep (recursive) or shallow.
 *
 * Used by {@see GitFilesFeatureTest::test_excluded_directories_are_not_deep_listed} to prove that
 * Git-excluded directories are never recursed into.
 */
class ListContentsSpyFileSystem extends FileSystem
{
    /** @var array<int, array{location: string, deep: bool}> */
    public array $listContentsCalls = [];

    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        $this->listContentsCalls[] = ['location' => $location, 'deep' => $deep];

        return parent::listContents($location, $deep);
    }
}
