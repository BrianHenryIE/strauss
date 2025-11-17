<?php

/**
 * Symbols found in autoloaded files should be prefixed, unless:
 * * The `exclude_from_prefix` rules apply to the discovered symbols.
 * * The file is in `exclude_from_copy`
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Config\MarkSymbolsForRenamingConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class MarkSymbolsForRenaming
{
    use LoggerAwareTrait;

    protected MarkSymbolsForRenamingConfigInterface $config;

    protected FileSystem $filesystem;

    public function __construct(
        MarkSymbolsForRenamingConfigInterface $config,
        FileSystem                            $filesystem,
        LoggerInterface                       $logger
    ) {
        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->setLogger($logger);
    }

    public function scanSymbols(DiscoveredSymbols $symbols)
    {
        foreach ($symbols->getSymbols() as $symbol) {
            // $this->config->getFlatDependencyTree
            $symbol->setDoRename(
                $this->fileIsAutoloaded($symbol)
                && !$this->excludeFromPrefix($symbol)
                && !$this->excludeFromCopy($symbol)
            );
        }
    }

    protected function fileIsAutoloaded(DiscoveredSymbol $symbol): bool
    {
        return array_reduce(
            $symbol->getSourceFiles(),
            fn(bool $carry, File $fileBase) => $carry && $fileBase->isAutoloaded(),
            true
        );
    }

    protected function excludeFromPrefix(DiscoveredSymbol $symbol): bool
    {

        return !$this->isExcludeFromPrefixPackage($symbol->getPackage())
            && !$this->isExcludeFromPrefixNamespace($symbol->getNamespace())
            && !$this->isExcludedFromPrefixFilePattern($symbol->getSourceFiles());
    }

    /**
     * If any of the files the symbol was found in are marked not to prefix, don't prefix the symbol.
     */
    protected function excludeFromCopy(DiscoveredSymbol $symbol): bool
    {
        return !array_reduce(
            $symbol->getSourceFiles(),
            fn(bool $carry, File $file) => $carry && $file->isDoPrefix(),
            true
        );

//        if (in_array($namespace, $this->config->getExcludeNamespacesFromCopy())) {
//            $this->logger->info("Excluding namespace " . $namespace);
//            return true;
//        }

//        if (in_array(
//            $packageName,
//            $this->config->getExcludePackagesFromCopy(),
//            true
//        )) {
//            return true;
//        }

//        foreach ($this->config->getExcludeFilePatternsFromCopy() as $excludeFilePattern) {
//            $vendorRelativePath = $this->filesystem->getRelativePath($this->config->getVendorDirectory(), $absoluteFilePath);
//            if (1 === preg_match($this->preparePattern($excludeFilePattern), $vendorRelativePath)) {
//                return true;
//            }
//        }
    }

    public function markFilesForExclusion(DiscoveredFiles $files): void
    {
        foreach ($files->getFiles() as $file) {
            if ($file instanceof FileWithDependency) {
                if (in_array(
                    $file->getDependency()->getPackageName(),
                    $this->config->getExcludePackagesFromPrefixing(),
                    true
                )) {
                    $file->setDoPrefix(false);
                    continue;
                }

                foreach ($this->config->getExcludeFilePatternsFromPrefixing() as $excludeFilePattern) {
                    // TODO: This source relative path should be from the vendor dir.
                    // TODO: Should the target path be used here?
                    if (1 === preg_match($excludeFilePattern, $file->getVendorRelativePath())) {
                        $file->setDoPrefix(false);
                        foreach ($file->getDiscoveredSymbols() as $discoveredSymbol) {
                            $discoveredSymbol->setDoRename(false);
                        }
                    }
                }
            }
        }
    }

    protected function isExcludeFromPrefixPackage(string $packageName): bool
    {
        if (in_array(
            $packageName,
            $this->config->getExcludePackagesFromPrefixing(),
            true
        )) {
            return true;
        }

        return false;
    }

    protected function isExcludeFromPrefixNamespace(?string $namespace): bool
    {
        if (empty($namespace)) {
            return false;
        }

        foreach ($this->config->getExcludeNamespacesFromPrefixing() as $excludeNamespace) {
            $excludeNamespace = rtrim($excludeNamespace, '\\');
            if (str_starts_with($namespace, $excludeNamespace)) {
                return true;
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
    protected function isExcludedFromPrefixFilePattern(array $files): bool
    {
        /** @var File $file */
        foreach ($files as $file) {
            $absoluteFilePath = $file->getAbsoluteTargetPath();
            foreach ($this->config->getExcludeFilePatternsFromPrefixing() as $excludeFilePattern) {
                $vendorRelativePath = $this->filesystem->getRelativePath($this->config->getVendorDirectory(), $absoluteFilePath);
                if (1 === preg_match($this->preparePattern($excludeFilePattern), $vendorRelativePath)) {
                    return true;
                }
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
