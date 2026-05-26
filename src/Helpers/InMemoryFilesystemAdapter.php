<?php

namespace BrianHenryIE\Strauss\Helpers;

use Elazar\Flystream\StripProtocolPathNormalizer;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter as LeagueInMemoryFilesystemAdapter;
use League\Flysystem\PathNormalizer;
use League\Flysystem\WhitespacePathNormalizer;

class InMemoryFilesystemAdapter extends LeagueInMemoryFilesystemAdapter implements FlysystemBackCompatTraitInterface
{
    use FlysystemBackCompatTrait;

    protected PathNormalizer $normalizer;

    /**
     * @see FlysystemBackCompatTrait::directoryExists()
     */
    public function getNormalizer(): PathNormalizer
    {
        if (!isset($this->normalizer)) {
            $this->normalizer = new StripProtocolPathNormalizer(['mem'], new WhitespacePathNormalizer());
        }
        return $this->normalizer;
    }

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

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->createDirectories($destination, $config);

        parent::copy($source, $destination, $config);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        // Make sure there is a directory for the file to be written to.
        if (false === strpos($path, '______DUMMY_FILE_FOR_FORCED_LISTING_IN_FLYSYSTEM_TEST')) {
            $this->createDirectories($path, $config);
        }

        parent::write($path, $contents, $config);
    }

    protected function createDirectories(string $path, Config $config): void
    {
        $pathDirs = explode('/', dirname($path));
        for ($level = 0; $level < count($pathDirs); $level++) {
            $dir = implode('/', array_slice($pathDirs, 0, $level + 1));
            $this->createDirectory($dir, $config);
        }
    }
}
