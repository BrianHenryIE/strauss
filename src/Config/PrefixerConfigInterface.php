<?php

/**
 * The configuration needed when applying the planned prefixes to file contents.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Config;

/**
 * Implemented by {@see \BrianHenryIE\Strauss\Composer\Extra\StraussConfig} and consumed by
 * {@see \BrianHenryIE\Strauss\Pipeline\Prefixer}. Provides the vendor and target directories, the namespace, classmap
 * and constant prefixes, the `exclude_from_prefix` rules, and whether this is a dry run.
 */
interface PrefixerConfigInterface
{
    public function getAbsoluteVendorDirectory(): string;

    public function getAbsoluteTargetDirectory(): string;

    public function isTargetDirectoryVendor(): bool;

    public function getNamespacePrefix(): ?string;

    public function getClassmapPrefix(): ?string;

    public function getConstantsPrefix(): ?string;

    /**
     * @return string[]
     */
    public function getExcludePackagesFromPrefixing(): array;

    /**
     * @return string[]
     */
    public function getExcludeNamespacesFromPrefixing(): array;

    /**
     * @return string[]
     */
    public function getExcludeFilePatternsFromPrefixing(): array;

    public function isDryRun(): bool;
}
