<?php

namespace BrianHenryIE\Strauss\Config;

interface CleanupConfigInterface
{
    public function getVendorDirectory(): string;

    public function isDeleteVendorFiles(): bool;

    public function isDeleteVendorPackages(): bool;

    public function getTargetDirectory(): string;

    public function isDryRun(): bool;

    /**
     * The directory containing `composer.json`.
     */
    public function getProjectDirectory(): string;

    /**
     * Packages to exclude from copying (and therefore from deletion).
     *
     * @return string[]
     */
    public function getExcludePackagesFromCopy(): array;
}
