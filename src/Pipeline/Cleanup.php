<?php
/**
 * Deletes source files and empty directories.
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\CleanupConfigInterface;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Pipeline\Cleanup\InstalledJson;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class Cleanup
{
    use LoggerAwareTrait;

    protected Filesystem $filesystem;

    protected bool $isDeleteVendorFiles;
    protected bool $isDeleteVendorPackages;

    protected CleanupConfigInterface $config;

    public function __construct(
        CleanupConfigInterface $config,
        Filesystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;

        $this->isDeleteVendorFiles = $config->isDeleteVendorFiles() && $config->getTargetDirectory() !== $config->getVendorDirectory();
        $this->isDeleteVendorPackages = $config->isDeleteVendorPackages() && $config->getTargetDirectory() !== $config->getVendorDirectory();

        $this->filesystem = $filesystem;
    }

    /**
     * Maybe delete the source files that were copied (depending on config),
     * then delete empty directories.
     *
     * @param File[] $files
     *
     * @throws FilesystemException
     */
    public function cleanup(array $files): void
    {
        if (!$this->isDeleteVendorPackages && !$this->isDeleteVendorFiles) {
            $this->logger->info('No cleanup required.');
            return;
        }

        $this->logger->info('Beginning cleanup.');

        if ($this->isDeleteVendorPackages) {
            $this->doIsDeleteVendorPackages($files);
        } elseif ($this->isDeleteVendorFiles) {
            $this->doIsDeleteVendorFiles($files);
        }

        $this->deleteEmptyDirectories($files);
    }

    /** @param array<string,ComposerPackage> $flatDependencyTree */
    public function cleanupVendorInstalledJson(array $flatDependencyTree, DiscoveredSymbols $discoveredSymbols): void
    {
        $installedJson = new InstalledJson(
            $this->config,
            $this->filesystem,
            $this->logger
        );

        if ($this->config->getTargetDirectory() !== $this->config->getVendorDirectory()
        && !$this->config->isDeleteVendorFiles() && !$this->config->isDeleteVendorPackages()
        ) {
            $installedJson->createAndCleanTargetDirInstalledJson($flatDependencyTree, $discoveredSymbols);
        } elseif ($this->config->getTargetDirectory() !== $this->config->getVendorDirectory()
            &&
            ($this->config->isDeleteVendorFiles() ||$this->config->isDeleteVendorPackages())
        ) {
            $installedJson->createAndCleanTargetDirInstalledJson($flatDependencyTree, $discoveredSymbols);
            $installedJson->cleanupVendorInstalledJson($flatDependencyTree, $discoveredSymbols);
        } elseif ($this->config->getTargetDirectory() === $this->config->getVendorDirectory()) {
            $installedJson->cleanupVendorInstalledJson($flatDependencyTree, $discoveredSymbols);
        }
    }

    /**
     * @throws FilesystemException
     */
    protected function deleteEmptyDirectories(array $files)
    {
        $this->logger->info('Deleting empty directories.');

        $sourceFiles = array_map(
            fn($file) => $file->getSourcePath(),
            $files
        );

        // Get the root folders of the moved files.
        $rootSourceDirectories = [];
        foreach ($sourceFiles as $sourceFile) {
            $arr = explode("/", $sourceFile, 2);
            $dir = $arr[0];
            $rootSourceDirectories[ $dir ] = $dir;
        }
        $rootSourceDirectories = array_map(
            function (string $path): string {
                return $this->config->getVendorDirectory() . $path;
            },
            array_keys($rootSourceDirectories)
        );

        foreach ($rootSourceDirectories as $rootSourceDirectory) {
            if (!$this->filesystem->directoryExists($rootSourceDirectory) || is_link($rootSourceDirectory)) {
                continue;
            }

            $dirList = $this->filesystem->listContents($rootSourceDirectory, true);

            $allFilePaths = array_map(
                fn($file) => $file->path(),
                $dirList->toArray()
            );

            // Sort by longest path first, so subdirectories are deleted before the parent directories are checked.
            usort(
                $allFilePaths,
                fn($a, $b) => count(explode('/', $b)) - count(explode('/', $a))
            );

            foreach ($allFilePaths as $filePath) {
                if ($this->filesystem->directoryExists($filePath)
                    && $this->filesystem->dirIsEmpty($filePath)
                ) {
                    $this->logger->debug('Deleting empty directory ' . $filePath);
                    $this->filesystem->deleteDirectory($filePath);
                }
            }
        }

//        foreach ($this->filesystem->listContents($this->getAbsoluteVendorDir()) as $dirEntry) {
//            if ($dirEntry->isDir() && $this->dirIsEmpty($dirEntry->path()) && !is_link($dirEntry->path())) {
//                $this->logger->info('Deleting empty directory ' .  $dirEntry->path());
//                $this->filesystem->deleteDirectory($dirEntry->path());
//            } else {
//                $this->logger->debug('Skipping non-empty directory ' . $dirEntry->path());
//            }
//        }
    }

    /**
     * @param array<File> $files
     */
    protected function doIsDeleteVendorPackages(array $files)
    {
        $this->logger->info('Deleting original vendor packages.');

        $packages = [];
        foreach ($files as $file) {
            if ($file instanceof FileWithDependency) {
                $packages[ $file->getDependency()->getPackageName() ] = $file->getDependency();
            }
        }

        /** @var ComposerPackage $package */
        foreach ($packages as $package) {
            // Normal package.
            $this->logger->info('Deleting ' . $package->getPackageAbsolutePath());

            $this->filesystem->deleteDirectory($package->getPackageAbsolutePath());

            if ($this->filesystem->dirIsEmpty(dirname($package->getPackageAbsolutePath()))) {
                $this->logger->info('Deleting empty directory ' . dirname($package->getPackageAbsolutePath()));
                $this->filesystem->deleteDirectory(dirname($package->getPackageAbsolutePath()));
            }
        }
    }

    /**
     * @param array<string, File> $files
     *
     * @throws FilesystemException
     */
    public function doIsDeleteVendorFiles(array $files)
    {
        $this->logger->info('Deleting original vendor files.');

        foreach ($files as $path => $file) {
            if (! $file->isDoDelete()) {
                $this->logger->debug('Skipping/preserving ' . $file->getSourcePath());
                continue;
            }

            $sourceRelativePath = $this->filesystem->getRelativePath($this->config->getVendorDirectory(), $path);

            $this->logger->info('Deleting ' . $sourceRelativePath);

            $this->filesystem->delete($file->getSourcePath());

            $file->setDidDelete(true);
        }
    }
}
