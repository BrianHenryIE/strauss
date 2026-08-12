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
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespacedSymbol;
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


    /**
     * If a file is autoloaded, mark it to be renamed, except when there is a rule excluding it.
     * If a namespace matches a namespace replacement pattern, then doRename needs to be set true.
     *
     * There are packages that have conditionally loaded classes, e.g. `art4/requests-psr18-adapter`'s `v1-compat`.
     */
    public function scanSetDoRename(DiscoveredSymbols $symbols): void
    {
        $namespaceReplacementPatterns = $this->config->getNamespaceReplacementPatterns();

        $allSymbols = $symbols->getSymbols();
        foreach ($allSymbols as $symbol) {
            if ($symbol instanceof NamespaceSymbol && $symbol->getOriginalFqdnName() === '\\') {
                continue;
            }

            $doRename = $symbol->isAutoloaded();

            foreach ($namespaceReplacementPatterns as $namespaceReplacementPatternFrom => $namespaceReplacementPatternTo) {
                $namespaceString = $symbol instanceof NamespaceSymbol
                    ? $symbol->getOriginalFqdnName()
                    : $symbol->getNamespace()->getOriginalFqdnName();

                if (1 === preg_match($this->preparePattern($namespaceReplacementPatternFrom), $namespaceString)) {
                    $doRename = true;
                    break;
                }
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
            /**
             * I'm not sure what this was added for, but psr-0 namespaces that are found when scanning autoload
             * keys are used to check do files exist under that directory.
             */
            if (!$this->config->isTargetDirectoryVendor()
                && !$this->isSymbolFoundInFileThatIsCopied($symbol)
                && !($symbol instanceof NamespaceSymbol)
            ) {
                $symbol->setDoRename(false);
            }

            $symbol->setDoRename($doRename);
        }
    }

    /**
     * Check the `exclude_from_prefix` rules for this symbol's package name, namespace and file-paths.
     */
    protected function excludeFromPrefix(DiscoveredSymbol $symbol): bool
    {
        return $this->isExcludeFromPrefixPackage($symbol->getPackageName())
            || $this->isExcludedFromPrefixFilePattern($symbol->getSourceFiles())
            || ( $symbol instanceof NamespacedSymbol && $this->isExcludeFromPrefixNamespace($symbol->getNamespaceName()))
            || ( $symbol instanceof NamespaceSymbol && $this->isExcludeFromPrefixNamespace($symbol->getOriginalFqdnName()));
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
        if ($this->config->isTargetDirectoryVendor()) {
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
        if ($this->config->isTargetDirectoryVendor()) {
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
            $absoluteFilePath = $file->getTargetAbsolutePath();
            if (empty($absoluteFilePath)) {
                // root namespace is in a fake file.
                continue;
            }
            $vendorRelativePath = $file->getVendorRelativePath();
            foreach ($this->config->getExcludeFilePatternsFromPrefixing() as $excludeFilePattern) {
                if (1 === preg_match($this->preparePattern($excludeFilePattern), $vendorRelativePath)) {
                    $file->setDoPrefix(false);
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
            || $this->isExcludeConstantsNamespace($symbol->getNamespaceName())
            || $this->isExcludedConstantsFilePattern($symbol->getSourceFiles())
            || $this->isExcludeConstantName($symbol->getOriginalFqdnName());
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
            $absoluteFilePath = $file->getTargetAbsolutePath();
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
