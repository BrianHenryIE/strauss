<?php
/**
 * Build a list of files from the composer autoloaders.
 *
 * Also record the `files` autoloaders.
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
     * Record the files autoloaders for later use in building our own autoloader.
     *
     * Package-name: [ dir1, file1, file2, ... ].
     *
     * @var array<string, string[]>
     */
    protected array $filesAutoloaders = [];

    /**
     * Copier constructor.
     */
    public function __construct(
        FileEnumeratorConfig $config,
        FileSystem $filesystem,
        ?LoggerInterface $logger = null
    ) {
        $this->discoveredFiles = new DiscoveredFiles();

        $this->config = $config;

        $this->filesystem = $filesystem;

        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Read the autoload keys of the dependencies and generate a list of the files referenced.
     *
     * Includes all files in the directories and subdirectories mentioned in the autoloaders.
     *
     * @param ComposerPackage[] $dependencies
     *
     * @throws FilesystemException
     */
    public function compileFileListForDependencies(array $dependencies): DiscoveredFiles
    {
        foreach ($dependencies as $dependency) {
            $this->logger->info("Scanning for files for package {packageName}", ['packageName' => $dependency->getPackageName()]);

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

            $dependencyPackageAbsolutePath = $dependency->getPackageAbsolutePath();

            foreach ($autoloaders as $type => $value) {
                // Might have to switch/case here.

                if ('files' === $type) {
                    // TODO: This is not in use.
                    $this->filesAutoloaders[$dependency->getVendorSubdir()] = $value;
                }

                foreach ($value as $namespace => $namespaceRelativePaths) {
                    if (!empty($namespace) && in_array($namespace, $this->config->getExcludeNamespacesFromCopy())) {
                        $this->logger->info("Excluding namespace " . $namespace);
                        continue;
                    }

                    $namespaceRelativePaths = (array) $namespaceRelativePaths;
//                    if (! is_array($namespace_relative_paths)) {
//                        $namespace_relative_paths = array( $namespace_relative_paths );
//                    }

                    foreach ($namespaceRelativePaths as $namespaceRelativePath) {
                        $sourceAbsoluteDirPath = in_array($namespaceRelativePath, ['.','./'])
                            ? $dependencyPackageAbsolutePath
                            : $dependencyPackageAbsolutePath . $namespaceRelativePath;

                        // If it is a directory, we need to list + add all files in it.
                        if ($this->filesystem->directoryExists($sourceAbsoluteDirPath)) {
                            $fileList = $this->filesystem->listContents($sourceAbsoluteDirPath, true);
                            $actualFileList = $fileList->toArray();

                            foreach ($actualFileList as $foundFile) {
                                $sourceAbsoluteFilepath = $foundFile->path();
                                // No need to record the directory itself.
                                if (!$this->filesystem->fileExists($sourceAbsoluteFilepath)
                                    ||
                                    $this->filesystem->directoryExists($sourceAbsoluteFilepath)
                                ) {
                                    continue;
                                }

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
    protected function addFileWithDependency(
        ComposerPackage $dependency,
        string $sourceAbsoluteFilepath,
        string $autoloaderType
    ): void {

        // Do not add a file if its source does not exist!
        if (!$this->filesystem->fileExists($sourceAbsoluteFilepath)
            && !$this->filesystem->directoryExists($sourceAbsoluteFilepath)) {
            $this->logger->warning("File does not exist: {sourcePath}", ['sourcePath' => $sourceAbsoluteFilepath]);
            return;
        }

        $vendorRelativePath = $this->filesystem->getRelativePath(
            $this->config->getVendorDirectory(),
            $sourceAbsoluteFilepath
        );

        $isOutsideProjectDir = $this->filesystem->normalize($dependency->getRealPath())
                               !== $this->filesystem->normalize($dependency->getPackageAbsolutePath());

        /** @var FileWithDependency $f */
        $f = $this->discoveredFiles->getFile($sourceAbsoluteFilepath)
            ?? new FileWithDependency($dependency, $vendorRelativePath, $sourceAbsoluteFilepath);

        $f->setAbsoluteTargetPath($this->config->getTargetDirectory() . $vendorRelativePath);

        $f->addAutoloader($autoloaderType);
        $f->setDoDelete(!$isOutsideProjectDir);

        $this->discoveredFiles->add($f);

        $relativeFilePath =
            $this->filesystem->getRelativePath(
                dirname($this->config->getVendorDirectory()),
                $f->getAbsoluteTargetPath()
            );
        $this->logger->info("Found file " . $relativeFilePath);
    }

    /**
     * @param string[] $paths
     */
    public function compileFileListForPaths(array $paths): DiscoveredFiles
    {
        $absoluteFilePaths = $this->filesystem->findAllFilesAbsolutePaths($paths);

        foreach ($absoluteFilePaths as $sourceAbsolutePath) {
            $f = $this->discoveredFiles->getFile($sourceAbsolutePath)
                 ?? new File($sourceAbsolutePath);

            $this->discoveredFiles->add($f);
        }

        $this->discoveredFiles->sort();
        return $this->discoveredFiles;
    }
}
