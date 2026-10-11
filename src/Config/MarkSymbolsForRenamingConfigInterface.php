<?php
/**
 * The configuration needed to decide which discovered symbols (namespaces, classes, functions, constants) should be
 * renamed, after applying the prefix, copy and constant exclusion rules.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Config;

/**
 * Implemented by {@see \BrianHenryIE\Strauss\Composer\Extra\StraussConfig} and consumed by
 * {@see \BrianHenryIE\Strauss\Pipeline\MarkSymbolsForRenaming}. Provides the vendor and target directories, the
 * `exclude_from_prefix`, `exclude_from_copy` and `exclude_constants` rules, explicit constant names to skip, and the
 * namespace replacement patterns, whose namespaces are always renamed.
 */
interface MarkSymbolsForRenamingConfigInterface
{
    public function getAbsoluteVendorDirectory(): string;

    public function getAbsoluteTargetDirectory(): string;

    public function isTargetDirectoryVendor(): bool;

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

    /**
     * Config: extra.strauss.exclude_constants – applied only to constants.
     *
     * @return string[]
     */
    public function getExcludePackagesFromConstantPrefixing(): array;

    /**
     * @return string[]
     */
    public function getExcludeNamespacesFromConstantPrefixing(): array;

    /**
     * @return string[]
     */
    public function getExcludeFilePatternsFromConstantPrefixing(): array;

    /**
     * Explicit constant names to never prefix (e.g. WP_PLUGIN_DIR, ABSPATH).
     *
     * @return string[]
     */
    public function getExcludeConstantNames(): array;

    /**
     * All specific replacements obviously should be marked for renaming.
     *
     * @return array<string, string>
     */
    public function getNamespaceReplacementPatterns(): array;
}
