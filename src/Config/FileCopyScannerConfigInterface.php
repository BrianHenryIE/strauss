<?php

/**
 * The configuration needed to decide, file by file, whether each discovered file should be copied to the target
 * directory and whether its original should be deleted.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Config;

/**
 * Implemented by {@see \BrianHenryIE\Strauss\Composer\Extra\StraussConfig} and consumed by
 * {@see \BrianHenryIE\Strauss\Pipeline\FileCopyScanner}. Provides the `exclude_from_copy` package, namespace and
 * file-pattern rules, the vendor and target directories, and the `delete_vendor_files` flag.
 */
interface FileCopyScannerConfigInterface
{
    public function getAbsoluteVendorDirectory(): string;

    public function isTargetDirectoryVendor(): bool;

    /**
     * @return string[]
     */
    public function getExcludePackagesFromCopy(): array;

    /**
     * @return string[]
     */
    public function getExcludeNamespacesFromCopy(): array;

    /**
     * @return string[]
     */
    public function getExcludeFilePatternsFromCopy(): array;

    public function isDeleteVendorFiles(): bool;

    public function getAbsoluteTargetDirectory(): string;
}
