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
use Psr\Log\NullLogger;

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

    public function compileFileListForDependencies(array $dependencies): DiscoveredFiles
    {
        foreach ($dependencies as $dependency) {
            $this->logger->info("Scanning for files for package {packageName}", ['packageName' => $dependency->getPackageName()]);
            $dependencyPackageAbsolutePath = $dependency->getPackageAbsolutePath();
            $this->compileFileListForPaths([$dependencyPackageAbsolutePath], $dependency);
        }


        $this->discoveredFiles->sort();
        return $this->discoveredFiles;
    }

    /**
     * @param string[] $paths
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
     * @param ComposerPackage $dependency
     * @param string $sourceAbsoluteFilepath
     * @param string $autoloaderType
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

        // Do not add a file if its source does not exist!
        if (!$this->filesystem->fileExists($sourceAbsoluteFilepath)
        ) {
//            && !$this->filesystem->directoryExists($sourceAbsoluteFilepath)) {
            $this->logger->warning("File does not exist: {sourcePath}", ['sourcePath' => $sourceAbsoluteFilepath]);
            return;
        }

        $isOutsideProjectDir = $this->filesystem->normalize($dependency->getRealPath())
                               !== $this->filesystem->normalize($dependency->getPackageAbsolutePath());

        if ($dependency) {
//            $vendorRelativePath = $this->filesystem->getRelativePath(
//                $this->config->getVendorDirectory(),
//                $sourceAbsoluteFilepath
//            );
            $vendorRelativePath = substr(
                $sourceAbsoluteFilepath,
                strpos($sourceAbsoluteFilepath, $dependency->getRelativePath() ?: 0)
            );

            if ($vendorRelativePath === $sourceAbsoluteFilepath) {
                $vendorRelativePath = $dependency->getRelativePath() . str_replace($dependency->getPackageAbsolutePath(), '', $sourceAbsoluteFilepath);
            }

            /** @var FileWithDependency $f */
            $f = $this->discoveredFiles->getFile($sourceAbsoluteFilepath)
                ?? new FileWithDependency($dependency, $vendorRelativePath, $sourceAbsoluteFilepath);

//            $f->setAbsoluteTargetPath($this->config->getVendorDirectory() . $vendorRelativePath);
            $f->setAbsoluteTargetPath($this->config->getTargetDirectory() . $vendorRelativePath);

            $autoloaderType && $f->addAutoloader($autoloaderType);
            //         $f->setDoDelete(!$isOutsideProjectDir);
            $f->setDoDelete($isOutsideProjectDir);
        } else {
            $vendorRelativePath = str_replace($this->config->getVendorDirectory(), '', $sourceAbsoluteFilepath);
            $vendorRelativePath = str_replace($this->config->getTargetDirectory(), '', $vendorRelativePath);

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
