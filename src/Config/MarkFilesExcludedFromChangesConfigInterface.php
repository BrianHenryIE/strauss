<?php
/**
 * The configuration needed to mark files that match the `exclude_files_from_update` rules so they are not edited.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Config;

/**
 * Implemented by {@see \BrianHenryIE\Strauss\Composer\Extra\StraussConfig} and consumed by
 * {@see \BrianHenryIE\Strauss\Pipeline\MarkFilesExcludedFromChanges}, returning the package, namespace and file-pattern
 * lists from that config key.
 */
interface MarkFilesExcludedFromChangesConfigInterface
{
    /**
     * @return string[]
     */
    public function getExcludeFilesFromUpdatePackages(): array;

    /**
     * @return string[]
     */
    public function getExcludeFileFromUpdateNamespaces(): array;

    /**
     * @return string[]
     */
    public function getExcludeFilesFromUpdateFilePatterns(): array;
}
