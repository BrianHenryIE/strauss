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

    protected string $workingDir;

    protected bool $isDeleteVendorFiles;
    protected bool $isDeleteVendorPackages;

    protected string $vendorDirectory;
    protected string $targetDirectory;

    protected CleanupConfigInterface $config;

    public function __construct(
        CleanupConfigInterface $config,
        string $workingDir,
        Filesystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;

        $this->vendorDirectory = $config->getVendorDirectory();
        $this->targetDirectory = $config->getTargetDirectory();
        $this->workingDir = $workingDir;

        $this->isDeleteVendorFiles = $config->isDeleteVendorFiles() && $config->getTargetDirectory() !== $config->getVendorDirectory();
        $this->isDeleteVendorPackages = $config->isDeleteVendorPackages() && $config->getTargetDirectory() !== $config->getVendorDirectory();

        $this->filesystem = $filesystem;
    }

    protected function getAbsoluteVendorDir(): string
    {
        return $this->workingDir . $this->vendorDirectory;
    }

    /**
     * Maybe delete the source files that were copied (depending on config),
     * then delete empty directories.
     *
     * @param File[] $files
     *
     * @throws FilesystemException
     */
    public function cleanup(array $files, array $flatDependencyTree, DiscoveredSymbols $discoveredSymbols): void
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

        $installedJson = new InstalledJson(
            $this->workingDir,
            $this->config,
            $this->filesystem,
            $this->logger
        );
        $installedJson->createAndCleanTargetDirInstalledJson($flatDependencyTree, $discoveredSymbols);
        $installedJson->cleanupVendorInstalledJson($flatDependencyTree, $discoveredSymbols);
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
                return $this->getAbsoluteVendorDir() . $path;
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
                fn($a, $b) => strlen($b) - strlen($a)
            );

            foreach ($allFilePaths as $filePath) {
                if ($this->filesystem->directoryExists($filePath)
                    && $this->dirIsEmpty($filePath)
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

    // TODO: Move to FileSystem class.
    protected function dirIsEmpty(string $dir): bool
    {
        // TODO BUG this deletes directories with only symlinks inside. How does it behave with hidden files?
        return empty($this->filesystem->listContents($dir)->toArray());
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
            if (str_starts_with($package->getPackageAbsolutePath(), $this->workingDir)) {
                $this->logger->info('Deleting ' . $package->getPackageAbsolutePath());

                $this->filesystem->deleteDirectory($package->getPackageAbsolutePath());
            } else {
                // TODO: log _where_ the symlink is pointing to.
                $this->logger->info('Deleting symlink at ' . $package->getRelativePath());

                // If it's a symlink, remove the symlink in the directory
                $symlinkPath =
                    rtrim(
                        $this->workingDir . $this->config->getVendorDirectory() . $package->getRelativePath(),
                        '/'
                    );

                if (false !== strpos('WIN', PHP_OS)) {
                    /**
                     * `unlink()` will not work on Windows. `rmdir()` will not work if there are files in the directory.
                     * "On windows, take care that `is_link()` returns false for Junctions."
                     *
                     * @see https://www.php.net/manual/en/function.is-link.php#113263
                     * @see https://stackoverflow.com/a/18262809/336146
                     */
                    rmdir($symlinkPath);
                } else {
                    unlink($symlinkPath);
                }
            }
            if ($this->dirIsEmpty(dirname($package->getPackageAbsolutePath()))) {
                $this->logger->info('Deleting empty directory ' . dirname($package->getPackageAbsolutePath()));
                $this->filesystem->deleteDirectory(dirname($package->getPackageAbsolutePath()));
            }
        }
    }

    /**
     * @param array $files
     *
     * @throws FilesystemException
     */
    public function doIsDeleteVendorFiles(array $files)
    {
        $this->logger->info('Deleting original vendor files.');

        foreach ($files as $file) {
            if (! $file->isDoDelete()) {
                $this->logger->debug('Skipping/preserving ' . $file->getSourcePath());
                continue;
            }

            $sourceRelativePath = $file->getSourcePath();

            $this->logger->info('Deleting ' . $sourceRelativePath);

            $this->filesystem->delete($file->getSourcePath());

            $file->setDidDelete(true);
        }
    }
}
