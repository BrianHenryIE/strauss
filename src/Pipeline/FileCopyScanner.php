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

use BrianHenryIE\Strauss\Config\FileCopyScannerConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use League\Flysystem\FilesystemReader;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class FileCopyScanner
{
    use LoggerAwareTrait;

    protected FileCopyScannerConfigInterface $config;

    protected string $workingDir;

    public function __construct(
        string $workingDir,
        FileCopyScannerConfigInterface $config,
        FilesystemReader $filesystem,
        ?LoggerInterface $logger = null
    ) {
        $this->workingDir = $workingDir;
        $this->config = $config;

        $this->setLogger($logger ?? new NullLogger());
    }

    public function scanFiles(DiscoveredFiles $files): void
    {
        /** @var FileBase $file */
        foreach ($files->getFiles() as $file) {
            $copy = true;

            if ($file instanceof FileWithDependency) {
                if (in_array($file->getDependency()->getPackageName(), $this->config->getExcludePackagesFromCopy(), true)) {
                    $copy = false;
                }
            }

            if ($this->config->getTargetDirectory() === $this->config->getVendorDirectory()) {
                $copy = false;
            }

            $fileSymbols = $file->getDiscoveredSymbols();
            $excludedNamespaces = $this->config->getExcludeNamespacesFromCopy();
            /** @var DiscoveredSymbol $symbol */
            foreach ($fileSymbols as $symbol) {
                foreach ($excludedNamespaces as $namespace) {
                    if ($symbol->getSourceFile() === $file
                    && $symbol instanceof NamespaceSymbol
                        && str_starts_with($symbol->getOriginalSymbol(), $namespace)
                    ) {
                        $copy = false;
                    }
                }
            }

            $filePath = $file->getSourcePath($this->workingDir . $this->config->getVendorDirectory());
            foreach ($this->config->getExcludeFilePatternsFromCopy() as $pattern) {
                if (1 == preg_match($pattern, $filePath)) {
                    $copy = false;
                }
            }

            $file->setDoCopy($copy);

            $target = $copy && $file instanceof FileWithDependency
                ? $this->workingDir . $this->config->getTargetDirectory() . $file->getVendorRelativePath()
                : $file->getSourcePath();

            $file->setAbsoluteTargetPath(
                $target
            );

            $file->setDoDelete($this->config->isDeleteVendorFiles() && ! $this->isSymlinkedFile($file));
        };
    }

    /**
     * Check does the filepath point to a file outside the working directory.
     * If `realpath()` fails to resolve the path, assume it's a symlink.
     *
     * TODO: This should not be here. It's a filesystem operation.
     */
    protected function isSymlinkedFile(FileBase $file): bool
    {
        $realpath = realpath($file->getSourcePath());

        return ! $realpath || ! str_starts_with($realpath, $this->workingDir);
    }
}
