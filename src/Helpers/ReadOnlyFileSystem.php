<?php
/**
 * When running with `--dry-run` the filesystem should be read-only.
 *
 * This should work with read operations working as normal but write operations should be
 * cached so they appear to have been successful but are not actually written to disk.
 */

namespace BrianHenryIE\Strauss\Helpers;

use BrianHenryIE\Strauss\Config\ReadOnlyFileSystemConfigInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathNormalizer;

class ReadOnlyFileSystem extends Filesystem
{

    protected ReadOnlyFileSystemConfigInterface $config;

    public function __construct(
        ReadOnlyFileSystemConfigInterface $readOnlyFileSystemConfig,
        string $workingDir,
        FilesystemAdapter $adapter,
        array $config = [],
        PathNormalizer $pathNormalizer = null
    ) {
        parent::__construct($adapter, $config, $pathNormalizer);

        $this->config = $readOnlyFileSystemConfig;
        $this->workingDir = $workingDir;
    }

    protected function replaceTargetDirWithSourceDir(string $dir): string
    {
        if (strpos($dir, $this->workingDir . $this->config->getTargetDirectory()) === 0) {
            $dir = str_replace($this->workingDir . $this->config->getTargetDirectory(), $this->workingDir . $this->config->getVendorDirectory(), $dir);
        }
        return $dir;
    }

    public function fileExists(string $location): bool
    {
        return parent::fileExists(
            $this->replaceTargetDirWithSourceDir($location)
        );
    }

    public function read(string $location): string
    {
        return parent::read(
            $this->replaceTargetDirWithSourceDir($location)
        );
    }

    public function write(string $location, string $contents, array $config = []): void
    {
    }

    public function delete(string $location): void
    {
    }

    public function deleteDirectory(string $location): void
    {
    }

    public function createDirectory(string $location, array $config = []): void
    {
    }
}
