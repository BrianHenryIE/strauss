<?php
/**
 * Build a list of files for the Composer packages.
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\DependenciesCollection;
use BrianHenryIE\Strauss\Config\FileEnumeratorConfig;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class FileEnumerator
{
    use LoggerAwareTrait;

    protected FileEnumeratorConfig $config;

    protected FileSystem $filesystem;

    protected DiscoveredFiles $discoveredFiles;

    /**
     * Copier constructor.
     */
    public function __construct(
        FileEnumeratorConfig $config,
        FileSystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->discoveredFiles = new DiscoveredFiles();

        $this->config = $config;

        $this->filesystem = $filesystem;

        $this->logger = $logger;
    }

    /**
     * @param ComposerPackage[] $flatDependencies
     *
     * @throws FilesystemException
     */
    public function compileFileListForDependencies(DependenciesCollection $flatDependencies): DiscoveredFiles
    {
        /** @var ComposerPackage $dependency */
        foreach ($flatDependencies as $dependency) {
            $this->logger->info("Scanning for files for package {packageName}", ['packageName' => $dependency->getPackageName()]);
            /** @var string $dependencyPackageAbsolutePath */
            $dependencyPackageAbsolutePath = $dependency->getPackageAbsolutePath();
            // Meta packages.
            if (is_null($dependencyPackageAbsolutePath)) {
                continue;
            }
            $this->compileFileListForPaths([$dependencyPackageAbsolutePath], $dependency);
        }

        $this->discoveredFiles->sort();
        return $this->discoveredFiles;
    }

    /**
     * @param string[] $paths
     * @throws FilesystemException
     */
    public function compileFileListForPaths(array $paths, ?ComposerPackage $dependency = null): DiscoveredFiles
    {
        $absoluteFilePaths = $this->filesystem->findAllFilesAbsolutePaths($paths);

        foreach ($absoluteFilePaths as $sourceAbsolutePath) {
            $this->addFile($sourceAbsolutePath, $dependency);
        }

        $this->discoveredFiles->sort();
        return $this->discoveredFiles;
    }

    /**
     * @param string $sourceAbsoluteFilepath
     * @param ?ComposerPackage $dependency
     * @param ?string $autoloaderType
     *
     * @throws FilesystemException
     * @uses DiscoveredFiles::add
     *
     */
    protected function addFile(
        string $sourceAbsoluteFilepath,
        ?ComposerPackage $dependency = null,
        ?string $autoloaderType = null
    ): void {

        if ($this->filesystem->directoryExists($sourceAbsoluteFilepath)) {
            $this->logger->debug("Skipping directory at {sourcePath}", ['sourcePath' => $sourceAbsoluteFilepath]);
            return;
        }

        // Do not add a file if its source does not exist!
        if (!$this->filesystem->fileExists($sourceAbsoluteFilepath)) {
            $this->logger->warning("File does not exist: {sourcePath}", ['sourcePath' => $sourceAbsoluteFilepath]);
            return;
        }

        if ($dependency) {
            $isOutsideProjectDir = !str_starts_with($dependency->getRealPath(), $this->config->getProjectAbsolutePath());

            $vendorRelativePath = $this->filesystem->getRelativePath(
                $this->config->getAbsoluteVendorDirectory(),
                $sourceAbsoluteFilepath
            );

            /** @var string $dependencyPackageAbsolutePath */
            $dependencyPackageAbsolutePath = $dependency->getPackageAbsolutePath();
            if ($vendorRelativePath === $sourceAbsoluteFilepath) {
                $vendorRelativePath = $dependency->getRelativePath() . str_replace(
                    FileSystem::normalizeDirSeparator($dependencyPackageAbsolutePath),
                    '',
                    FileSystem::normalizeDirSeparator($sourceAbsoluteFilepath)
                );
            }

            /** @var FileWithDependency $f */
            $f = $this->discoveredFiles->getFile($sourceAbsoluteFilepath)
                ?? new FileWithDependency(
                    $dependency,
                    FileSystem::normalizeDirSeparator($vendorRelativePath),
                    $this->filesystem->normalizePath($sourceAbsoluteFilepath),
                    $this->config->getAbsoluteTargetDirectory(). '/' . $vendorRelativePath
                );

//            $f->setTargetAbsolutePath($this->config->getAbsoluteTargetDirectory() . '/' . $vendorRelativePath);

            $autoloaderType && $f->addAutoloader($autoloaderType);

//            if ($isOutsideProjectDir) {
//                $f->setDoDelete(false);
//            }
        } else {
            $vendorRelativePath = $this->filesystem->getRelativePath(
                str_starts_with($sourceAbsoluteFilepath, $this->config->getAbsoluteVendorDirectory()) ? $this->config->getAbsoluteVendorDirectory() : $this->config->getAbsoluteTargetDirectory(),
                $sourceAbsoluteFilepath,
            );

            $targetAbsolutePath = $this->config->getAbsoluteTargetDirectory() . '/' . $vendorRelativePath;

            $f = $this->discoveredFiles->getFile($sourceAbsoluteFilepath)
                 ?? new File(
                     FileSystem::normalizeDirSeparator($sourceAbsoluteFilepath),
                     $vendorRelativePath,
                     $targetAbsolutePath
                 );
        }

        $this->discoveredFiles->add($f);

        $vendorRelativeFilePath =
            $this->filesystem->getRelativePath(
                $this->config->getProjectAbsolutePath(),
                $f->getSourcePath()
            );
        $this->logger->info("Found file " . $vendorRelativeFilePath);
    }
}
