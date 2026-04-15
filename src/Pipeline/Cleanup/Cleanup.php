<?php
/**
 * Deletes source files and empty directories.
 */

namespace BrianHenryIE\Strauss\Pipeline\Cleanup;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\CleanupConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Pipeline\Autoload\DumpAutoload;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use Composer\Autoload\AutoloadGenerator;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Json\JsonFile;
use Composer\Repository\InstalledFilesystemRepository;
use Exception;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Seld\JsonLint\ParsingException;

/**
 * @phpstan-import-type InstalledJsonArray from InstalledJson
 */
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
     * @param array<string,ComposerPackage> $flatDependencyTree
     *
     * @throws FilesystemException
     */
    public function deleteFiles(array $flatDependencyTree, DiscoveredFiles $discoveredFiles): void
    {
        if (!$this->isDeleteVendorPackages && !$this->isDeleteVendorFiles) {
            $this->logger->info('No cleanup required.');
            return;
        }

        $this->logger->info('Beginning cleanup.');

        if ($this->isDeleteVendorPackages) {
            $this->doIsDeleteVendorPackages($flatDependencyTree, $discoveredFiles);
        }

        if ($this->isDeleteVendorFiles) {
            $this->doIsDeleteVendorFiles($discoveredFiles->getFiles());
        }

        $this->deleteEmptyDirectories($discoveredFiles->getFiles());
    }

    /** @param array<string,ComposerPackage> $flatDependencyTree
     * @throws Exception
     * @throws FilesystemException
     */
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
            $installedJson->cleanTargetDirInstalledJson($flatDependencyTree, $discoveredSymbols);
        } elseif ($this->config->getTargetDirectory() !== $this->config->getVendorDirectory()
            &&
            ($this->config->isDeleteVendorFiles() ||$this->config->isDeleteVendorPackages())
        ) {
            $installedJson->cleanTargetDirInstalledJson($flatDependencyTree, $discoveredSymbols);
            $installedJson->cleanupVendorInstalledJson($flatDependencyTree, $discoveredSymbols);
        } elseif ($this->config->getTargetDirectory() === $this->config->getVendorDirectory()) {
            $installedJson->cleanupVendorInstalledJson($flatDependencyTree, $discoveredSymbols);
        }
    }

    /**
     * After packages or files have been deleted, the autoloader still contains references to them, in particular
     * `files` are `require`d on boot (whereas classes are on demand) so that must be fixed.
     *
     * Assumes {@see Cleanup::cleanupVendorInstalledJson()} has been called first.
     *
     * TODO refactor so this object is passed around rather than reloaded.
     *
     * Shares a lot of code with {@see DumpAutoload::generatedPrefixedAutoloader()} but I've done lots of work
     * on that in another branch so I don't want to cause merge conflicts.
     * @throws ParsingException
     */
    public function rebuildVendorAutoloader(): void
    {
        if ($this->config->isDryRun()) {
            return;
        }

        $projectComposerJson = new JsonFile($this->config->getProjectDirectory() . 'composer.json');
        $projectComposerJsonArray = $projectComposerJson->read();
        $composer = Factory::create(new NullIO(), $projectComposerJsonArray);
        $installationManager = $composer->getInstallationManager();
        $package = $composer->getPackage();
        $config = $composer->getConfig();
        $generator = new AutoloadGenerator($composer->getEventDispatcher());

        $generator->setClassMapAuthoritative(true);
        $generator->setRunScripts(false);
//        $generator->setApcu($apcu, $apcuPrefix);
//        $generator->setPlatformRequirementFilter($this->getPlatformRequirementFilter($input));
        $optimize = true; // $input->getOption('optimize') || $config->get('optimize-autoloader');
        $installedJson = new JsonFile($this->config->getVendorDirectory() . 'composer/installed.json');
        $localRepo = new InstalledFilesystemRepository($installedJson);
        $strictAmbiguous = false; // $input->getOption('strict-ambiguous')
        /** @var InstalledJsonArray $installedJsonArray */
        $installedJsonArray = $installedJson->read();
        $generator->setDevMode($installedJsonArray['dev'] ?? false);
        // This will output the autoload_static.php etc. files to `vendor/composer`.
        $generator->dump(
            $config,
            $localRepo,
            $package,
            $installationManager,
            'composer',
            $optimize,
            null,
            $composer->getLocker(),
            $strictAmbiguous
        );
    }

    /**
     * @param FileBase[] $files
     * @throws FilesystemException
     */
    protected function deleteEmptyDirectories(array $files): void
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

    /**
     * TODO: Move to FileSystem class.
     *
     * @throws FilesystemException
     */
    protected function dirIsEmpty(string $dir): bool
    {
        // TODO BUG this deletes directories with only symlinks inside. How does it behave with hidden files?
        return empty($this->filesystem->listContents($dir)->toArray());
    }

    /**
     * @param array<string,ComposerPackage> $flatDependencyTree
     * @throws FilesystemException
     */
    protected function doIsDeleteVendorPackages(array $flatDependencyTree, DiscoveredFiles $discoveredFiles): void
    {
        $this->logger->info('Deleting original vendor packages.');

//        if ($this->isDeleteVendorPackages) {
//            foreach ($flatDependencyTree as $packageName => $package) {
//                if ($package->isDoDelete()) {
//                    $this->filesystem->deleteDirectory($package->getPackageAbsolutePath());
//                    $package->setDidDelete(true);
////                $files = $package->getFiles();
////                foreach($files as $file){
////                    $file->setDidDelete(true);
////                }
//                }
//            }
//        }

        foreach ($flatDependencyTree as $package) {
            // Skip packages excluded from copy - they should remain in vendor/
            if (in_array($package->getPackageName(), $this->config->getExcludePackagesFromCopy(), true)) {
                $this->logger->debug('Skipping deletion of excluded package: ' . $package->getPackageName());
                continue;
            }

            // Normal package.
            if ($this->filesystem->isSubDirOf($this->config->getVendorDirectory(), $package->getPackageAbsolutePath())) {
                $this->logger->info('Deleting ' . $package->getPackageAbsolutePath());

                $this->filesystem->deleteDirectory($package->getPackageAbsolutePath());

                $package->setDidDelete(true);
            } else {
                // TODO: log _where_ the symlink is pointing to.
                $this->logger->info('Deleting symlink at ' . $package->getRelativePath());

                // If it's a symlink, remove the symlink in the directory
                $symlinkPath =
                    rtrim(
                        $this->config->getVendorDirectory() . $package->getRelativePath(),
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

                $package->setDidDelete(true);
            }
            if ($this->dirIsEmpty(dirname($package->getPackageAbsolutePath()))) {
                $this->logger->info('Deleting empty directory ' . dirname($package->getPackageAbsolutePath()));
                $this->filesystem->deleteDirectory(dirname($package->getPackageAbsolutePath()));
            }
        }
    }

    /**
     * @param FileBase[] $files
     *
     * @throws FilesystemException
     */
    public function doIsDeleteVendorFiles(array $files): void
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
