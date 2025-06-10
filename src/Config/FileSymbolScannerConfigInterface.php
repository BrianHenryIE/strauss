<?php
/**
 * @see \BrianHenryIE\Strauss\Composer\Extra\StraussConfig
 */

namespace BrianHenryIE\Strauss\Config;

interface FileSymbolScannerConfigInterface
{
    /**
     * @return string[]
     */
    public function getExcludeNamespacesFromPrefixing(): array;

    public function getPackagesToPrefix(): array;

    /**
     * Just for shortening paths to relative paths for logging.
     */
    public function getProjectDirectory(): string;
}
