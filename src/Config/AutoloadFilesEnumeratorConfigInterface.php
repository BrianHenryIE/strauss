<?php
/**
 * The configuration needed to decide, from each package's `autoload` key, which files are candidates for prefixing
 * and copying, after applying the `exclude_from_prefix` and `exclude_from_copy` rules.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Config;

/**
 * Implemented by {@see \BrianHenryIE\Strauss\Composer\Extra\StraussConfig} and consumed by
 * {@see \BrianHenryIE\Strauss\Pipeline\AutoloadedEnumerator}. Returns the vendor directory and the package, namespace
 * and file-pattern exclusion lists for both prefixing and copying.
 */
interface AutoloadFilesEnumeratorConfigInterface
{
    public function getAbsoluteVendorDirectory(): string;

    /**
     * @return string[]
     */
    public function getExcludePackagesFromPrefixing(): array;

    /**
     * @return string[]
     */
    public function getExcludeFilePatternsFromPrefixing(): array;

    /**
     * @return string[]
     */
    public function getExcludeNamespacesFromPrefixing(): array;
   /**
    * @return string[]
    */
    public function getExcludePackagesFromCopy(): array;

    /**
     * @return string[]
     */
    public function getExcludeFilePatternsFromCopy(): array;

    /**
     * @return string[]
     */
    public function getExcludeNamespacesFromCopy(): array;
}
