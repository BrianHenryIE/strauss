<?php
/**
 * Build a list of files for the Composer packages.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\DependenciesCollection;
use BrianHenryIE\Strauss\Config\FileEnumeratorConfig;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\Helpers\GitAttributes;
use Inmarelibero\GitIgnoreChecker\Exception\GitIgnoreCherkerException;
use Inmarelibero\GitIgnoreChecker\GitIgnoreChecker;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * Lists each package's (or path's) files through the {@see FileSystem}, first shallowly so that, when Git exclusion is
 * enabled, directories Git would exclude are never descended into, then filters the full list with
 * {@see GitIgnoreChecker} and {@see GitAttributes} `export-ignore` rules. Each remaining file is added to a single
 * {@see DiscoveredFiles} collection as a {@see FileWithDependency} (with its vendor-relative and target paths) or,
 * for files outside a package, a plain {@see File}.
 */
class FileEnumerator
{
    use LoggerAwareTrait;

    protected FileEnumeratorConfig $config;

    protected FileSystem $filesystem;

    protected DiscoveredFiles $discoveredFiles;

    /**
     * Copier constructor.
     *
     * @param FileEnumeratorConfig $config     Whether to exclude Git-ignored files, and the vendor, target and project directories.
     * @param FileSystem           $filesystem Used to list the files under each path.
     * @param LoggerInterface      $logger     Logger.
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
     * @param DependenciesCollection $flatDependencies All dependencies (flattened) whose package directories are scanned; meta packages are skipped.
     *
     * @throws FilesystemException
     */
    public function compileFileListForDependencies(DependenciesCollection $flatDependencies): DiscoveredFiles
    {
        /**
 * @var ComposerPackage $dependency
*/
        foreach ($flatDependencies as $dependency) {
            $this->logger->info("Scanning for files for package {packageName}", ['packageName' => $dependency->getPackageName()]);
            $dependencyPackageAbsolutePath = $dependency->getPackageAbsolutePath();
            // Meta packages.
            if (is_null($dependencyPackageAbsolutePath)) {
                continue;
            }
            $this->compileFileListForPaths([$dependencyPackageAbsolutePath], $dependency);
//            $absoluteFilePaths = $this->filesystem->findAllFilesAbsolutePaths([$dependencyPackageAbsolutePath]);
//
//            foreach ($absoluteFilePaths as $sourceAbsolutePath) {
//                $this->addFile($sourceAbsolutePath, $dependency);
//            }
        }

        $this->discoveredFiles->sort();
        return $this->discoveredFiles;
    }

    /**
     * @param string[]         $paths      Absolute paths of the files and directories to scan, recursively.
     * @param ?ComposerPackage $dependency The package the paths belong to, or null for project files (e.g. call sites).
     *
     * @throws FilesystemException
     */
    public function compileFileListForPaths(
        array $paths,
        ?ComposerPackage $dependency = null
    ): DiscoveredFiles {
        // First, shallowly list each path's top-level entries (files and directories, non-recursive).
        $directoryListingByPath = [];
        foreach ($paths as $path) {
            $directoryListingByPath[$path] = $this->filesystem->findAllFilesAbsolutePaths([$path], false, false);
        }

        if ($this->config->isExcludeGitFiles()) {
            // Apply the Git exclusion rules to each path's top-level entries before recursing, so we
            // never deep-list (descend into) directories that Git would exclude, e.g. `.git`.
            foreach ($directoryListingByPath as $path => $files) {
                $directoryListingByPath[$path] = $this->excludeGitFiles([$path], $files);
            }
        }

        $absoluteFilePaths = $this->filesystem->findAllFilesAbsolutePaths(array_merge(...array_values($directoryListingByPath)));

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
     * @param string[] $basePaths         Absolute paths of the directories, each treated as a repository root.
     * @param string[] $absoluteFilePaths Absolute paths of the files (and directories) to filter.
     *
     * @return string[]
     * @throws FilesystemException
     */
    protected function excludeGitFiles(array $basePaths, array $absoluteFilePaths): array
    {
        /**
 * @var array<string, array{gitignore?:GitIgnoreChecker, gitattributes?:GitAttributes}> $repositories
*/
        $repositories = [];
        foreach ($basePaths as $basePath) {
            if (!$this->filesystem->directoryExists($basePath)) {
                continue;
            }

            $normalizedBasePath = rtrim(FileSystem::normalizeDirSeparator($basePath), '/');

            // A `.git` directory is never part of the distributed package, so its presence alone is
            // enough to enable pruning – even without a `.gitignore`/`.gitattributes`. Registering the
            // base path here ensures `isGitExcluded()` runs its `.git` check for it.
            if ($this->filesystem->directoryExists($normalizedBasePath . '/.git')) {
                $repositories[$normalizedBasePath] ??= [];
            }

            if ($this->filesystem->fileExists($normalizedBasePath . '/.gitignore')) {
                try {
                    /**
                     * TODO: use {@see FileSystem::prefixPath()} when #278 is merged.
                     */
                    $gitIgnoreChecker = new GitIgnoreChecker('/' . $normalizedBasePath);
                    $repositories[$normalizedBasePath][ 'gitignore'] = $gitIgnoreChecker;
                } catch (GitIgnoreCherkerException $e) {
                    // e.g. when the path is not on the local filesystem (in-memory tests).
                    // The user explicitly enabled Git exclusion, so surface the failure to honour it.
                    $this->logger->warning(
                        "Could not read .gitignore at {path}: {message}",
                        [
                        'path' => $normalizedBasePath,
                        'message' => $e->getMessage(),
                        ]
                    );
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

        return array_values(
            array_filter(
                $absoluteFilePaths,
                fn(string $sourceAbsolutePath): bool => !$this->isGitExcluded($sourceAbsolutePath, $repositories)
            )
        );
    }

    /**
     * @param string                                                                          $sourceAbsolutePath Absolute path of the file or directory to check.
     * @param array<string, array{gitignore?:GitIgnoreChecker, gitattributes?:GitAttributes}> $repositories       Git checkers, keyed by repository root absolute path.
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
                    $this->logger->warning(
                        "Could not check .gitignore for {path}: {message}",
                        [
                        'path' => $sourceAbsolutePath,
                        'message' => $e->getMessage(),
                        ]
                    );
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
     * @param string           $sourceAbsoluteFilepath Absolute path of the file; directories are ignored.
     * @param ?ComposerPackage $dependency             The package the file belongs to; null for project files.
     * @param ?string          $autoloaderType         Autoload key type the file is in, e.g. `files` or `classmap` (currently never passed).
     *
     * @throws FilesystemException
     * @uses   DiscoveredFiles::add
     */
    protected function addFile(
        string $sourceAbsoluteFilepath,
        ?ComposerPackage $dependency = null,
        ?string $autoloaderType = null
    ): void {

        // Check if we are dealing with a file or directory.
        if ($this->filesystem->directoryExists($sourceAbsoluteFilepath)) {
            return;
        }

        // Do not add a file if its source does not exist!
        if (!$this->filesystem->fileExists($sourceAbsoluteFilepath)) {
            $this->logger->warning("File does not exist: {sourcePath}", ['sourcePath' => $sourceAbsoluteFilepath]);
            return;
        }

        if ($dependency) {
            $vendorRelativePath = $this->filesystem->getRelativePath(
                $this->config->getAbsoluteVendorDirectory(),
                $sourceAbsoluteFilepath
            );

            /**
 * @var string $dependencyPackageAbsolutePath
*/
            $dependencyPackageAbsolutePath = $dependency->getPackageAbsolutePath();
            if ($vendorRelativePath === $sourceAbsoluteFilepath) {
                $vendorRelativePath = $dependency->getRelativePath() . str_replace(
                    FileSystem::normalizeDirSeparator($dependencyPackageAbsolutePath),
                    '',
                    FileSystem::normalizeDirSeparator($sourceAbsoluteFilepath)
                );
            }

            /**
 * @var FileWithDependency $f
*/
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

        $vendorRelativeFilePath
            = $this->filesystem->getRelativePath(
                $this->config->getProjectAbsolutePath(),
                $f->getSourcePath()
            );
        $this->logger->info("Found file " . $vendorRelativeFilePath);
    }
}
