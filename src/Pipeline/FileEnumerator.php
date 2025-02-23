<?php
/**
 * Build a list of files from the composer autoloaders.
 *
 * Also record the `files` autoloaders.
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Helpers\Path;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class FileEnumerator
{
    use LoggerAwareTrait;

    /**
     * The only path variable with a leading slash.
     * All directories in project end with a slash.
     *
     * @var string
     */
    protected string $workingDir;

    /** @var string */
    protected string $vendorDir;

    /** @var string[]  */
    protected array $excludePackageNames = array();

    /** @var string[]  */
    protected array $excludeNamespaces = array();

    /** @var string[]  */
    protected array $excludeFilePatterns = array();

    protected Filesystem $filesystem;

    protected DiscoveredFiles $discoveredFiles;

    /**
     * Record the files autoloaders for later use in building our own autoloader.
     *
     * Package-name: [ dir1, file1, file2, ... ].
     *
     * @var array<string, string[]>
     */
    protected array $filesAutoloaders = [];

    /**
     * Copier constructor.
     *
     * @param string $workingDir
     */
    public function __construct(
        string $workingDir,
        StraussConfig $config,
        FileSystem $filesystem,
        ?LoggerInterface $logger = null
    ) {
        $this->discoveredFiles = new DiscoveredFiles();

        $this->workingDir = $workingDir;
        $this->vendorDir = $config->getVendorDirectory();

        $this->excludeNamespaces = $config->getExcludeNamespacesFromCopy();
        $this->excludePackageNames = $config->getExcludePackagesFromCopy();
        $this->excludeFilePatterns = $config->getExcludeFilePatternsFromCopy();

        $this->filesystem = $filesystem;

        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Read the autoload keys of the dependencies and generate a list of the files referenced.
     *
     * Includes all files in the directories and subdirectories mentioned in the autoloaders.
     *
     * @param ComposerPackage[] $dependencies
     */
    public function compileFileListForDependencies(array $dependencies): DiscoveredFiles
    {
        foreach ($dependencies as $dependency) {
            if (in_array($dependency->getPackageName(), $this->excludePackageNames)) {
                $this->logger->info("Excluding package " . $dependency->getPackageName());
                continue;
            }
            $this->logger->info("Scanning for files for package " . $dependency->getPackageName());

            /**
             * Where $dependency->autoload is ~
             *
             * [ "psr-4" => [ "BrianHenryIE\Strauss" => "src" ] ]
             * Exclude "exclude-from-classmap"
             * @see https://getcomposer.org/doc/04-schema.md#exclude-files-from-classmaps
             */
            $autoloaders = array_filter($dependency->getAutoload(), function ($type) {
                return 'exclude-from-classmap' !== $type;
            }, ARRAY_FILTER_USE_KEY);

            foreach ($autoloaders as $type => $value) {
                // Might have to switch/case here.

                if ('files' === $type) {
                    // TODO: This is not in use.
                    $this->filesAutoloaders[$dependency->getRelativePath()] = $value;
                }

                foreach ($value as $namespace => $namespace_relative_paths) {
                    if (!empty($namespace) && in_array($namespace, $this->excludeNamespaces)) {
                        $this->logger->info("Excluding namespace " . $namespace);
                        continue;
                    }

                    $namespace_relative_paths = (array) $namespace_relative_paths;
//                    if (! is_array($namespace_relative_paths)) {
//                        $namespace_relative_paths = array( $namespace_relative_paths );
//                    }

                    foreach ($namespace_relative_paths as $namespaceRelativePath) {
                        $sourceAbsoluteDirPath = in_array($namespaceRelativePath, ['.','./'])
                            ? $dependency->getPackageAbsolutePath()
                            : $dependency->getPackageAbsolutePath() . $namespaceRelativePath;

                        if ($this->filesystem->directoryExists($sourceAbsoluteDirPath)) {
                            // trailingslashit(). (to remove duplicates).
                            $sourcePath = Path::normalize($sourceAbsoluteDirPath);

                            $fileList = $this->filesystem->listContents($sourcePath, true);
                            $actualFileList = $fileList->toArray();

                            foreach ($actualFileList as $foundFile) {
                                $sourceAbsoluteFilepath = '/'. $foundFile->path();
                                // No need to record the directory itself.
                                if (!$this->filesystem->fileExists($sourceAbsoluteFilepath)
                                    ||
                                    $this->filesystem->directoryExists($sourceAbsoluteFilepath)
                                ) {
                                    continue;
                                }
                                $namespaceRelativePath = Path::normalize($namespaceRelativePath);

                                $this->addFileWithDependency(
                                    $dependency,
                                    $sourceAbsoluteFilepath,
                                    $type
                                );
                            }
                        } else {
                            $this->addFileWithDependency($dependency, $sourceAbsoluteDirPath, $type);
                        }
                    }
                }
            }
        }

        return $this->discoveredFiles;
    }

    /**
     * @param ComposerPackage $dependency
     * @param string $packageRelativePath
     * @param string $autoloaderType
     *
     * @throws FilesystemException
     * @uses \BrianHenryIE\Strauss\Files\DiscoveredFiles::add()
     *
     */
    protected function addFileWithDependency(ComposerPackage $dependency, string $sourceAbsoluteFilepath, string $autoloaderType): void
    {
        $vendorRelativePath = substr($sourceAbsoluteFilepath, strpos($sourceAbsoluteFilepath, $dependency->getRelativePath()));

        if ($vendorRelativePath === $sourceAbsoluteFilepath) {
            $vendorRelativePath = $dependency->getRelativePath() . str_replace($dependency->getPackageAbsolutePath(), '', $sourceAbsoluteFilepath);
        }

        $isOutsideProjectDir = 0 !== strpos($sourceAbsoluteFilepath, $this->workingDir);

        /** @var FileWithDependency $f */
        $f = $this->discoveredFiles->getFile($sourceAbsoluteFilepath)
            ?? new FileWithDependency($dependency, $vendorRelativePath, $sourceAbsoluteFilepath);

        $f->setAbsoluteTargetPath($this->workingDir . $this->vendorDir . $vendorRelativePath);

        $f->addAutoloader($autoloaderType);
        $f->setDoDelete($isOutsideProjectDir);

        foreach ($this->excludeFilePatterns as $excludePattern) {
            if (1 === preg_match($excludePattern, $vendorRelativePath)) {
                $f->setDoCopy(false);
            }
        }

        $this->discoveredFiles->add($f);

        $this->logger->info("Found file " . $f->getAbsoluteTargetPath($this->workingDir));
    }

    /**
     * @param string[] $paths
     */
    public function compileFileListForPaths(array $paths): DiscoveredFiles
    {
        $absoluteFilePaths = $this->filesystem->findAllFilesAbsolutePaths($this->workingDir, $paths);

        foreach ($absoluteFilePaths as $sourceAbsolutePath) {
            $f = $this->discoveredFiles->getFile($sourceAbsolutePath)
                 ?? new File($sourceAbsolutePath);

            $this->discoveredFiles->add($f);
        }

        return $this->discoveredFiles;
    }
}
