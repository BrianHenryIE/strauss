<?php
/**
 * Deletes source files and empty directories.
 */

namespace BrianHenryIE\Strauss\Pipeline\Cleanup;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\DependenciesCollection;
use BrianHenryIE\Strauss\Config\CleanupConfigInterface;
use BrianHenryIE\Strauss\Config\OptimizeAutoloaderConfigInterface;
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

    protected FileSystem $filesystem;

    protected bool $isDeleteVendorFiles;
    protected bool $isDeleteVendorPackages;

    protected CleanupConfigInterface $config;

    public function __construct(
        CleanupConfigInterface $config,
        FileSystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;

        $this->isDeleteVendorFiles = $config->isDeleteVendorFiles() && $config->getAbsoluteTargetDirectory() !== $config->getAbsoluteVendorDirectory();
        $this->isDeleteVendorPackages = $config->isDeleteVendorPackages() && $config->getAbsoluteTargetDirectory() !== $config->getAbsoluteVendorDirectory();

        $this->filesystem = $filesystem;
    }

    /**
     * Maybe delete the source files that were copied (depending on config),
     * then delete empty directories.
     *
     * @throws FilesystemException
     */
    public function deleteFiles(DependenciesCollection $flatDependencyTree, DiscoveredFiles $discoveredFiles): void
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

    /**
     * @throws Exception
     * @throws FilesystemException
     */
    public function cleanupVendorInstalledJson(DependenciesCollection $flatDependencyTree, DiscoveredSymbols $discoveredSymbols): void
    {
        $installedJson = new InstalledJson(
            $this->config,
            $this->filesystem,
            $this->logger
        );

        if (!$this->config->isTargetDirectoryVendor()
            && !$this->config->isDeleteVendorFiles()
            && !$this->config->isDeleteVendorPackages()
        ) {
            $installedJson->cleanTargetDirInstalledJson($flatDependencyTree, $discoveredSymbols);
        } elseif (!$this->config->isTargetDirectoryVendor()
            && ($this->config->isDeleteVendorFiles() || $this->config->isDeleteVendorPackages())
        ) {
            $installedJson->cleanTargetDirInstalledJson($flatDependencyTree, $discoveredSymbols);
            $installedJson->cleanupVendorInstalledJson($flatDependencyTree, $discoveredSymbols);
        } elseif ($this->config->isTargetDirectoryVendor()) {
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

        $projectComposerJson = new JsonFile(
            $this->filesystem->makeAbsolute(
                $this->config->getProjectAbsolutePath() . '/composer.json' // Factory::getComposerFile();
            )
        );
        $projectComposerJsonArray = $projectComposerJson->read();
        $composer = Factory::create(new NullIO(), $projectComposerJsonArray);
        $installationManager = $composer->getInstallationManager();
        $package = $composer->getPackage();
        $config = $composer->getConfig();
        $generator = new AutoloadGenerator($composer->getEventDispatcher());
        $isOptimize = $this->isOptimizeAutoloaderEnabled();
        $generator->setClassMapAuthoritative($isOptimize);
        $generator->setRunScripts(false);
//        $generator->setApcu($apcu, $apcuPrefix);
//        $generator->setPlatformRequirementFilter($this->getPlatformRequirementFilter($input));
        $installedJson = new JsonFile(
            $this->filesystem->makeAbsolute(
                $this->config->getAbsoluteVendorDirectory() . '/composer/installed.json'
            )
        );
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
            $isOptimize,
            null,
            $composer->getLocker(),
            $strictAmbiguous
        );
    }

    /**
     * Keep backward compatibility with configs implementing only CleanupConfigInterface.
     */
    protected function isOptimizeAutoloaderEnabled(): bool
    {
        return $this->config instanceof OptimizeAutoloaderConfigInterface
            ? $this->config->isOptimizeAutoloader()
            : true;
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
                return $this->config->getAbsoluteVendorDirectory() . '/' . $path;
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
                    && $this->filesystem->isDirectoryEmpty($filePath)
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
        $this->logger->debug('Finished Cleanup::deleteEmptyDirectories()');
    }

    /**
     * @throws FilesystemException
     */
    protected function doIsDeleteVendorPackages(DependenciesCollection $flatDependencyTree, DiscoveredFiles $discoveredFiles): void
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

            // Meta packages.
            if (is_null($package->getPackageAbsolutePath())) {
                continue;
            }

            // Normal package.
            $this->logger->info('Deleting ' . $package->getPackageAbsolutePath());

            $this->filesystem->deleteDirectory($package->getPackageAbsolutePath());

            $package->setDidDelete(true);

            $packageParentDir = dirname($package->getPackageAbsolutePath());
            if ($this->filesystem->isDirectoryEmpty($packageParentDir)) {
                $this->logger->info('Deleting empty directory ' . $packageParentDir);
                $this->filesystem->deleteDirectory($packageParentDir);
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

            // TODO: is this relative or absolute?
            $this->filesystem->delete($file->getSourcePath());

            $file->setDidDelete(true);
        }
    }
}
