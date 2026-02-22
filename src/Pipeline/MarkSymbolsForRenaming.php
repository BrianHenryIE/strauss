<?php

/**
 * Symbols found in autoloaded files should be prefixed, unless:
 * * The `exclude_from_prefix` rules apply to the discovered symbols.
 * * The file is in `exclude_from_copy`
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Config\MarkSymbolsForRenamingConfigInterface;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
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

    public function scanSymbols(DiscoveredSymbols $symbols): void
    {
        $allSymbols = $symbols->getSymbols();
        foreach ($allSymbols as $symbol) {
            // $this->config->getFlatDependencyTree

            if (!$this->fileIsAutoloaded($symbol)) {
//                $this->logger->debug()
                $symbol->setDoRename(false);
                continue;
            }

            // If the symbol's package is excluded from copy, don't prefix it
            if ($this->isExcludeFromCopyPackage($symbol->getPackageName())) {
                $symbol->setDoRename(false);
                continue;
            }

            if ($this->excludeFromPrefix($symbol)) {
                $symbol->setDoRename(false);
                continue;
            }

            // Constant-only exclusion: extra.strauss.exclude_constants
            if ($symbol instanceof ConstantSymbol && $this->isExcludeConstants($symbol)) {
                $symbol->setDoRename(false);
                continue;
            }

//            if ($this->isSymbolFoundInFileThatIsNotCopied($symbol)) {
//                if (count($symbol->getSourceFiles())===1) {
//                    $symbol->setDoRename(false);
//                }
//            }
            if ($this->config->getVendorDirectory() !== $this->config->getTargetDirectory()
                && !$this->isSymbolFoundInFileThatIsCopied($symbol)) {
                $symbol->setDoRename(false);
            }
        }
    }

    /**
     * If all the files a symbol is defined in are autoloaded, prefix the symbol.
     *
     * There are packages where a class may be defined in two different files and they are conditionally loaded.
     * TODO: How best to handle this scenario?
     */
    protected function fileIsAutoloaded(DiscoveredSymbol $symbol): bool
    {
        // The same namespace symbols are found in lots of files so this test isn't useful.
        if ($symbol instanceof NamespaceSymbol) {
            return true;
        }

        $sourceFiles = array_filter(
            $symbol->getSourceFiles(),
            fn (FileBase $file) => basename($file->getVendorRelativePath()) !== 'composer.json'
        );

        return array_reduce(
            $sourceFiles,
            fn(bool $carry, FileBase $fileBase) => $carry && $fileBase->isAutoloaded(),
            true
        );
    }

    /**
     * Check the `exclude_from_prefix` rules for this symbol's package name, namespace and file-paths.
     */
    protected function excludeFromPrefix(DiscoveredSymbol $symbol): bool
    {
        return $this->isExcludeFromPrefixPackage($symbol->getPackageName())
            || $this->isExcludeFromPrefixNamespace($symbol->getNamespace())
            || $this->isExcludedFromPrefixFilePattern($symbol->getSourceFiles());
    }

    /**
     * If any of the files the symbol was found in are marked not to prefix, don't prefix the symbol.
     *
     * `config.strauss.exclude_from_copy`.
     *
     * This requires {@see FileCopyScanner} to have been run first.
     */
    protected function isSymbolFoundInFileThatIsNotCopied(DiscoveredSymbol $symbol): bool
    {
        if ($this->config->getVendorDirectory() === $this->config->getTargetDirectory()) {
            return false;
        }

        return !array_reduce(
            $symbol->getSourceFiles(),
            fn(bool $carry, FileBase $file) => $carry && $file->isDoCopy(),
            true
        );
    }

    protected function isSymbolFoundInFileThatIsCopied(DiscoveredSymbol $symbol): bool
    {
        if ($this->config->getVendorDirectory() === $this->config->getTargetDirectory()) {
            return false;
        }

        return array_reduce(
            $symbol->getSourceFiles(),
            fn(bool $carry, FileBase $file) => $carry || $file->isDoCopy(),
            false
        );
    }

    /**
     * Config: `extra.strauss.exclude_from_copy.packages`.
     */
    protected function isExcludeFromCopyPackage(?string $packageName): bool
    {
        return !is_null($packageName) && in_array($packageName, $this->config->getExcludePackagesFromCopy(), true);
    }

    /**
     * Config: `extra.strauss.exclude_from_prefix.packages`.
     */
    protected function isExcludeFromPrefixPackage(?string $packageName): bool
    {
        if (is_null($packageName)) {
            return false;
        }

        if (in_array(
            $packageName,
            $this->config->getExcludePackagesFromPrefixing(),
            true
        )) {
            return true;
        }

        return false;
    }

    /**
     * Config: `extra.strauss.exclude_from_prefix.namespaces`.
     */
    protected function isExcludeFromPrefixNamespace(?string $namespace): bool
    {
        if (empty($namespace)) {
            return false;
        }

        foreach ($this->config->getExcludeNamespacesFromPrefixing() as $excludeNamespace) {
            if (str_starts_with($namespace, $excludeNamespace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compares the relative path from the vendor dir with `exclude_file_patterns` config.
     *
     * Config: `extra.strauss.exclude_from_prefix.file_patterns`.
     *
     * @param array<FileBase> $files
     */
    protected function isExcludedFromPrefixFilePattern(array $files): bool
    {
        /** @var File $file */
        foreach ($files as $file) {
            $absoluteFilePath = $file->getAbsoluteTargetPath();
            if (empty($absoluteFilePath)) {
                // root namespace is in a fake file.
                continue;
            }
            $vendorRelativePath = $file->getVendorRelativePath();
            foreach ($this->config->getExcludeFilePatternsFromPrefixing() as $excludeFilePattern) {
                if (1 === preg_match($this->preparePattern($excludeFilePattern), $vendorRelativePath)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Config: extra.strauss.exclude_constants – applies only to constants.
     */
    protected function isExcludeConstants(ConstantSymbol $symbol): bool
    {
        return $this->isExcludeConstantsPackage($symbol->getPackageName())
            || $this->isExcludeConstantsNamespace($symbol->getNamespace())
            || $this->isExcludedConstantsFilePattern($symbol->getSourceFiles())
            || $this->isExcludeConstantName($symbol->getOriginalSymbol());
    }

    protected function isExcludeConstantsPackage(?string $packageName): bool
    {
        if (is_null($packageName)) {
            return false;
        }
        return in_array($packageName, $this->config->getExcludePackagesFromConstantPrefixing(), true);
    }

    protected function isExcludeConstantsNamespace(?string $namespace): bool
    {
        if (empty($namespace)) {
            return false;
        }
        foreach ($this->config->getExcludeNamespacesFromConstantPrefixing() as $excludeNamespace) {
            if (str_starts_with($namespace, $excludeNamespace)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<FileBase> $files
     */
    protected function isExcludedConstantsFilePattern(array $files): bool
    {
        /** @var File $file */
        foreach ($files as $file) {
            $absoluteFilePath = $file->getAbsoluteTargetPath();
            if (empty($absoluteFilePath)) {
                continue;
            }
            $vendorRelativePath = $file->getVendorRelativePath();
            foreach ($this->config->getExcludeFilePatternsFromConstantPrefixing() as $excludeFilePattern) {
                if (1 === preg_match($this->preparePattern($excludeFilePattern), $vendorRelativePath)) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function isExcludeConstantName(string $constantName): bool
    {
        return in_array($constantName, $this->config->getExcludeConstantNames(), true);
    }

    /**
     * TODO: This should be moved into the class parsing the config.
     */
    private function preparePattern(string $pattern): string
    {
        $delimiter = '#';

        if (substr($pattern, 0, 1) !== substr($pattern, - 1, 1)) {
            $pattern = $delimiter . $pattern . $delimiter;
        }

        return $pattern;
    }
}
