<?php

/**
 * The configuration needed while parsing each file to discover the symbols it declares and uses.
 *
 * @package brianhenryie/strauss
 * @see     \BrianHenryIE\Strauss\Composer\Extra\StraussConfig
 */

namespace BrianHenryIE\Strauss\Config;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\DependenciesCollection;

/**
 * Consumed by {@see \BrianHenryIE\Strauss\Pipeline\FileSymbolScanner}, which uses the {@see ComposerPackage} list to
 * decide which packages' files are scanned and the project path to shorten paths in log messages.
 */
interface FileSymbolScannerConfigInterface
{
    /**
     * @return string[]
     */
    public function getExcludeNamespacesFromPrefixing(): array;

    /**
     * The packages in the dependency tree. Files of other packages, e.g. those not being prefixed when prefixing in
     * place, are only scanned if they mention a symbol found in these.
     *
     * @return ?DependenciesCollection Null when not known, so every file is scanned.
     */
    public function getAllPackages(): ?DependenciesCollection;

    /**
     * @return array<string,ComposerPackage>
     */
    public function getPackagesToPrefix(): array;

    /**
     * Just for shortening paths to relative paths for logging.
     */
    public function getProjectAbsolutePath(): string;
}
