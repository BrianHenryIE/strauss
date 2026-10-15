<?php

/**
 * The configuration needed by the cleanup step, which deletes the original vendor files or packages once they have
 * been copied and updates Composer's `installed.json` to match.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Config;

/**
 * Implemented by {@see \BrianHenryIE\Strauss\Composer\Extra\StraussConfig} and consumed by
 * {@see \BrianHenryIE\Strauss\Pipeline\Cleanup\Cleanup} and {@see \BrianHenryIE\Strauss\Pipeline\Cleanup\InstalledJson}.
 * The `delete_vendor_files`/`delete_vendor_packages` flags are only acted on when the target directory differs from
 * the vendor directory; packages excluded from copy are never deleted.
 */
interface CleanupConfigInterface
{
    public function getAbsoluteVendorDirectory(): string;

    public function isTargetDirectoryVendor(): bool;

    public function isDeleteVendorFiles(): bool;

    public function isDeleteVendorPackages(): bool;

    public function getAbsoluteTargetDirectory(): string;

    public function isDryRun(): bool;

    /**
     * The directory containing `composer.json`.
     */
    public function getProjectAbsolutePath(): string;

    /**
     * Packages to exclude from copying (and therefore from deletion).
     *
     * @return string[]
     */
    public function getExcludePackagesFromCopy(): array;
}
