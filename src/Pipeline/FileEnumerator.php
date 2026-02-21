<?php
/**
 * Build a list of files for the Composer packages.
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
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

    protected Filesystem $filesystem;

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
     * @param ComposerPackage[] $dependencies
     * @throws FilesystemException
     */
    public function compileFileListForDependencies(array $dependencies): DiscoveredFiles
    {
        foreach ($dependencies as $dependency) {
            $this->logger->info("Scanning for files for package {packageName}", ['packageName' => $dependency->getPackageName()]);
            /** @var string $dependencyPackageAbsolutePath */
            $dependencyPackageAbsolutePath = $dependency->getPackageAbsolutePath();
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

        $isOutsideProjectDir = 0 !== strpos($sourceAbsoluteFilepath, $this->config->getVendorDirectory());

        if ($dependency) {
            $vendorRelativePath = substr(
                $sourceAbsoluteFilepath,
                strpos($sourceAbsoluteFilepath, $dependency->getRelativePath()) ?: 0
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
                    $this->filesystem->normalize($vendorRelativePath),
                    $this->filesystem->normalize($sourceAbsoluteFilepath)
                );

            $f->setAbsoluteTargetPath($this->config->getVendorDirectory() . $vendorRelativePath);

            $autoloaderType && $f->addAutoloader($autoloaderType);
            $f->setDoDelete($isOutsideProjectDir);
        } else {
            $vendorRelativePath = str_replace(
                FileSystem::normalizeDirSeparator($this->config->getVendorDirectory()),
                '',
                FileSystem::normalizeDirSeparator($sourceAbsoluteFilepath)
            );
            $vendorRelativePath = str_replace(
                FileSystem::normalizeDirSeparator($this->config->getTargetDirectory()),
                '',
                $vendorRelativePath
            );

            $f = $this->discoveredFiles->getFile($sourceAbsoluteFilepath)
                 ?? new File($sourceAbsoluteFilepath, $vendorRelativePath);
        }

        $this->discoveredFiles->add($f);

        $relativeFilePath =
            $this->filesystem->getRelativePath(
                dirname($this->config->getVendorDirectory()),
                $f->getAbsoluteTargetPath()
            );
        $this->logger->info("Found file " . $relativeFilePath);
    }
}
