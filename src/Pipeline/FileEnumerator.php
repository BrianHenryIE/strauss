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
use BrianHenryIE\Strauss\Helpers\GitAttributes;
use Inmarelibero\GitIgnoreChecker\Exception\GitIgnoreCherkerException;
use Inmarelibero\GitIgnoreChecker\GitIgnoreChecker;
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
        // First get the files in the root of the path.
        $directoryListingByPath = [];
        foreach ($paths as $path) {
            $directoryListingByPath[$path] = $this->filesystem->findAllFilesAbsolutePaths([$path], false, false);
        }

        if ($this->config->isExcludeGitFiles()) {
            foreach ($directoryListingByPath as $path => $files) {
                $directoryListingByPath[$path] = $this->excludeGitFiles($paths, $files);
            }
        }

        $absoluteFilePaths = $this->filesystem->findAllFilesAbsolutePaths(...array_values($directoryListingByPath));

        if ($this->config->isExcludeGitFiles()) {
            $absoluteFilePaths = $this->excludeGitFiles($paths, $absoluteFilePaths);
        }

        foreach ($absoluteFilePaths as $sourceAbsolutePath) {
            $this->addFile($sourceAbsolutePath, $dependency);
        }

        $this->discoveredFiles->sort();
        return $this->discoveredFiles;
    }

    /**
     * Remove files which Git would not include in the package's distributed archive:
     * the `.git` directory, files matched by `.gitignore`, and files marked `export-ignore`
     * in `.gitattributes`. Each base path is treated as its own repository root.
     *
     * @param string[] $basePaths
     * @param string[] $absoluteFilePaths
     *
     * @return string[]
     * @throws FilesystemException
     */
    protected function excludeGitFiles(array $basePaths, array $absoluteFilePaths): array
    {
        /** @var array<string, array{gitignore?:GitIgnoreChecker, gitattributes?:GitAttributes}> $repositories */
        $repositories = [];
        foreach ($basePaths as $basePath) {
            if (!$this->filesystem->directoryExists($basePath)) {
                continue;
            }

            $normalizedBasePath = rtrim(FileSystem::normalizeDirSeparator($basePath), '/');

            if ($this->filesystem->fileExists($normalizedBasePath . '/.gitignore')) {
                try {
                    /**
                     * TODO: use {@see FileSystem::prefixPath()} when #278 is merged.
                     */
                    $gitIgnoreChecker = new GitIgnoreChecker('/' . $normalizedBasePath);
                    $repositories[$normalizedBasePath][ 'gitignore'] = $gitIgnoreChecker;
                } catch (GitIgnoreCherkerException $e) {
                    // e.g. when the path is not on the local filesystem (in-memory tests).
                    $this->logger->debug("Could not read .gitignore at {path}: {message}", [
                        'path' => $normalizedBasePath,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            if ($this->filesystem->fileExists($normalizedBasePath . '/.gitattributes')) {
                $repositories[$normalizedBasePath][ 'gitattributes'] = new GitAttributes($this->filesystem, $normalizedBasePath);
            }
        }

        if (empty($repositories)) {
            return $absoluteFilePaths;
        }

        $this->logger->info('Processing .gitignore/.gitattributes – checking ' . count($absoluteFilePaths) . ' files.');

        return array_values(array_filter(
            $absoluteFilePaths,
            fn(string $sourceAbsolutePath): bool => !$this->isGitExcluded($sourceAbsolutePath, $repositories)
        ));
    }

    /**
     * @param array<string, array{gitignore?:GitIgnoreChecker, gitattributes?:GitAttributes}> $repositories
     *
     * @throws FilesystemException
     */
    protected function isGitExcluded(string $sourceAbsolutePath, array $repositories): bool
    {
        foreach ($repositories as $basePath => $checkers) {
            $relativePath = $this->filesystem->getRelativePath($basePath, $sourceAbsolutePath);

            // Not located within this repository root.
            if ($relativePath === '' || strpos($relativePath, '../') === 0) {
                continue;
            }

            // The .git directory is never part of the distributed package.
            if ($relativePath === '.git' || strpos($relativePath, '.git/') === 0) {
                $this->logger->debug("Skipping .git file {path}", ['path' => $sourceAbsolutePath]);
                return true;
            }

            if (isset($checkers['gitignore'])) {
                try {
                    if ($checkers['gitignore']->isPathIgnored('/' . $relativePath)) {
                        $this->logger->debug("Skipping .gitignore'd file {path}", ['path' => $sourceAbsolutePath]);
                        return true;
                    }
                } catch (GitIgnoreCherkerException $e) {
                    $this->logger->debug("Could not check .gitignore for {path}: {message}", [
                        'path' => $sourceAbsolutePath,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            if (isset($checkers['gitattributes']) && $checkers['gitattributes']->isExportIgnored($relativePath)) {
                $this->logger->debug("Skipping export-ignore file {path}", ['path' => $sourceAbsolutePath]);
                return true;
            }
        }

        return false;
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

        $isOutsideProjectDir = 0 !== strpos($sourceAbsoluteFilepath, $this->config->getAbsoluteVendorDirectory());

        if ($dependency) {
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
                    FileSystem::normalizeDirSeparator($sourceAbsoluteFilepath)
                );

            $autoloaderType && $f->addAutoloader($autoloaderType);
            $f->setDoDelete($isOutsideProjectDir);
        } else {
            $vendorRelativePath = $this->filesystem->getRelativePath(
                str_starts_with($sourceAbsoluteFilepath, $this->config->getAbsoluteVendorDirectory()) ? $this->config->getAbsoluteVendorDirectory() : $this->config->getAbsoluteTargetDirectory(),
                $sourceAbsoluteFilepath,
            );

            $f = $this->discoveredFiles->getFile($sourceAbsoluteFilepath)
                 ?? new File(
                     FileSystem::normalizeDirSeparator($sourceAbsoluteFilepath),
                     $vendorRelativePath
                 );
        }

        $this->discoveredFiles->add($f);

        $relativeFilePath =
            $this->filesystem->getRelativePath(
                dirname($this->config->getAbsoluteVendorDirectory()),
                $f->getAbsoluteTargetPath()
            );
        $this->logger->info("Found file " . $relativeFilePath);
    }
}
