<?php
/**
 * @see \BrianHenryIE\Strauss\Composer\Extra\StraussConfig
 */

namespace BrianHenryIE\Strauss\Config;

use BrianHenryIE\Strauss\Composer\ComposerPackage;

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
    public function getProjectDirectory(): string;
}
