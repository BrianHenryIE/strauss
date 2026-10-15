<?php

/**
 * The configuration needed while parsing each file to discover the symbols it declares and uses.
 *
 * @package brianhenryie/strauss
 * @see     \BrianHenryIE\Strauss\Composer\Extra\StraussConfig
 */

namespace BrianHenryIE\Strauss\Config;

use BrianHenryIE\Strauss\Composer\ComposerPackage;

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
     * @return array<string,ComposerPackage>
     */
    public function getPackagesToPrefix(): array;

    /**
     * Just for shortening paths to relative paths for logging.
     */
    public function getProjectAbsolutePath(): string;
}
