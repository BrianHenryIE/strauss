<?php
/**
 * Loop over the discovered files and mark the file to be copied or not.
 *
 * ```
 * "exclude_from_copy": {
 *   "packages": [
 *   ],
 *   "namespaces": [
 *   ],
 *   "file_patterns": [
 *   ]
 * },
 * ```
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\FileCopyScannerConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class FileCopyScanner
{
    use LoggerAwareTrait;

    protected FileCopyScannerConfigInterface $config;

    protected FileSystem $filesystem;

    public function __construct(
        FileCopyScannerConfigInterface $config,
        FileSystem $filesystem,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->filesystem = $filesystem;

        $this->setLogger($logger ?? new NullLogger());
    }

    public function scanFiles(DiscoveredFiles $files): void
    {
        /** @var FileBase $file */
        foreach ($files->getFiles() as $file) {
            $copy = true;

            if ($this->config->isTargetDirectoryVendor()) {
                $this->logger->debug("The target directory is the same as the vendor directory."); // TODO: surely this should be outside the loop/class.
                $copy = false;
            }

            if ($file instanceof FileWithDependency) {
                if ($this->isPackageExcluded($file->getDependency())) {
                    $copy = false;
                    $this->logger->debug("File {sourcePath} will not be copied because {$file->getDependency()->getPackageName()} is excluded from copy.", [
                        'sourcePath' => $file->getSourcePath(),
                    ]);
                }
            }

            if ($this->isNamespaceExcluded($file)) {
                $copy = false;
            }

            if ($this->isFilePathExcluded($file)) {
                $copy = false;
            }

//            if ($copy) {
//                $this->logger->debug("Marking file {relativeFilePath} to be copied.", [
//                    'relativeFilePath' => $this->filesystem->getRelativePath($this->config->getAbsoluteVendorDirectory(), $file->getSourcePath()),
//                ]);
//            }

            $file->setDoCopy($copy);

            if ($copy) {
                $target = $file instanceof FileWithDependency
                    ?  $this->config->getAbsoluteTargetDirectory() . '/' . $file->getDependency()->getRelativePath() . '/'. $file->getPackageRelativePath()
                    : $file->getSourcePath();
                $file->setTargetAbsolutePath(FileSystem::normalizeDirSeparator($target));
            }

            $shouldDelete = $this->config->isDeleteVendorFiles() && ! $this->filesystem->isSymlinked($file->getSourcePath());
            $file->setDoDelete($shouldDelete);

            // If a file isn't copied, don't unintentionally edit the source file.
            if (!$file->isDoCopy() && !$this->config->isTargetDirectoryVendor()) {
                $file->setDoPrefix(false);
            }
//            // If the file is marked not to copy, mark the symbol not to be renamed
//            if (!$copy && !$this->config->isTargetDirectoryVendor()) {
//                foreach ($file->getDiscoveredSymbols() as $symbol) {
//                    // Only make this change if the symbol is only in one file (i.e. namespaces will be in many).
//                    if (count($symbol->getSourceFiles()) === 1) {
//                        $symbol->setDoRename(false);
//                    }
//                }
//            }
            // To make step-debugging easier.
            unset($copy, $target, $shouldDelete);
        };
    }

    protected function isPackageExcluded(ComposerPackage $package): bool
    {
        if (in_array(
            $package->getPackageName(),
            $this->config->getExcludePackagesFromCopy(),
            true
        )) {
            return true;
        }
        return false;
    }

    protected function isNamespaceExcluded(FileBase $file): bool
    {
        if (!$file->isPhpFile()) {
            return false;
        }
        $namespacesInFile = array_map(
            fn(NamespaceSymbol $symbol) => $symbol->getOriginalSymbol(),
            $file->getDiscoveredSymbols()->getNamespaces()->notGlobal()->toArray()
        );
        /** @var DiscoveredSymbol $symbol */
        foreach ($this->config->getExcludeNamespacesFromCopy() as $excludedNamespaceString) {
            $excludedNamespaceString = rtrim($excludedNamespaceString, '\\');

            $excludedNamespacesInFile = array_reduce(
                $namespacesInFile,
                // TODO: case insensitive check. People might write BrianHenryIE\API instead of BrianHenryIE\Api.
                fn(array $carry, string $namespace) => str_starts_with($namespace, $excludedNamespaceString)
                        ? array_merge($carry, [$namespace]) : $carry,
                []
            );
            if (!empty($excludedNamespacesInFile)) {
                $this->logger->debug("File {sourcePath} will not be copied because namespace {$excludedNamespaceString} is excluded from copy.", [
                    'sourcePath' => $file->getSourcePath(),
                ]);
                return true;
            }
        }
        return false;
    }

    /**
     * Compares the vendor relative path with `exclude_file_patterns` config.
     *
     * I.e. `my/package/src/file.php`.
     *
     * @param FileBase $file
     */
    protected function isFilePathExcluded(FileBase $file): bool
    {
        $path = $file->getVendorRelativePath();

        foreach ($this->config->getExcludeFilePatternsFromCopy() as $pattern) {
            $escapedPattern = $this->preparePattern($pattern);
            if (1 === preg_match($escapedPattern, $path)) {
                $this->logger->debug("File {path} will not be copied because it matches pattern {$pattern}.", [
                    'path' => $path
                ]);
                return true;
            }
        }
        return false;
    }

    private function preparePattern(string $pattern): string
    {
        $delimiter = '#';

        if (substr($pattern, 0, 1) !== substr($pattern, - 1, 1)) {
            $pattern = $delimiter . $pattern . $delimiter;
        }

        return $pattern;
    }
}
