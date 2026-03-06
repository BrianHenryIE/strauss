<?php

namespace BrianHenryIE\Strauss\Config;

interface MarkSymbolsForRenamingConfigInterface
{
    public function getVendorDirectory(): string;

    public function getTargetDirectory(): string;

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
}
