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
use BrianHenryIE\Strauss\Files\File;
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

            if ($this->config->getTargetDirectory() === $this->config->getVendorDirectory()) {
                $this->logger->debug("The target directory is the same as the vendor directory."); // TODO: surely this should be outside the loop/class.
                $copy = false;
            }

            if ($file instanceof FileWithDependency) {
                if ($this->isPackageExcluded($file->getDependency())) {
                    $copy = false;
                    $this->logger->debug("File {$file->getSourcePath()} will not be copied because {$file->getDependency()->getPackageName()} is excluded from copy.");
                }
            }

            if ($this->isNamespaceExcluded($file)) {
                $copy = false;
            }

            if ($this->isFilePathExcluded($file->getSourcePath())) {
                $copy = false;
            }

            if ($copy) {
//                $this->logger->debug("Marking file {relativeFilePath} to be copied.", [
//                    'relativeFilePath' => $this->filesystem->getRelativePath($this->config->getVendorDirectory(), $file->getSourcePath()),
//                ]);
            }

            $file->setDoCopy($copy);

            $target = $copy && $file instanceof FileWithDependency
                ? $this->config->getTargetDirectory() . $file->getVendorRelativePath()
                : $file->getSourcePath();

            $file->setAbsoluteTargetPath(FileSystem::normalizeDirSeparator($target));

            $shouldDelete = $this->config->isDeleteVendorFiles() && ! $this->filesystem->isSymlinkedFile($file);
            $file->setDoDelete($shouldDelete);

            // If a file isn't copied, don't unintentionally edit the source file.
            if (!$file->isDoCopy() && $this->config->getTargetDirectory() !== $this->config->getVendorDirectory()) {
                $file->setDoPrefix(false);
            }
//            // If the file is marked not to copy, mark the symbol not to be renamed
//            if (!$copy && $this->config->getTargetDirectory() !== $this->config->getVendorDirectory()) {
//                foreach ($file->getDiscoveredSymbols() as $symbol) {
//                    // Only make this change if the symbol is only in one file (i.e. namespaces will be in many).
//                    if (count($symbol->getSourceFiles()) === 1) {
//                        $symbol->setDoRename(false);
//                    }
//                }
//            }
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
        /** @var DiscoveredSymbol $symbol */
        foreach ($file->getDiscoveredSymbols() as $symbol) {
            if (!($symbol instanceof NamespaceSymbol)) {
                continue;
            }
            foreach ($this->config->getExcludeNamespacesFromCopy() as $namespace) {
                $namespace = rtrim($namespace, '\\');
                if (in_array($file->getSourcePath(), array_keys($symbol->getSourceFiles()), true)
                    // TODO: case insensitive check. People might write BrianHenryIE\API instead of BrianHenryIE\Api.
                    && str_starts_with($symbol->getOriginalSymbol(), $namespace)
                ) {
                    $this->logger->debug("File {$file->getSourcePath()} will not be copied because namespace {$namespace} is excluded from copy.");
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Compares the relative path from the vendor dir with `exclude_file_patterns` config.
     *
     * @param string $absoluteFilePath
     * @return bool
     */
    protected function isFilePathExcluded(string $absoluteFilePath): bool
    {
        foreach ($this->config->getExcludeFilePatternsFromCopy() as $pattern) {
            $escapedPattern = $this->preparePattern($pattern);
            if (1 === preg_match($escapedPattern, $absoluteFilePath)) {
                $this->logger->debug("File {$absoluteFilePath} will not be copied because it matches pattern {$pattern}.");
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
