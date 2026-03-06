<?php

namespace BrianHenryIE\Strauss\Config;

use BrianHenryIE\Strauss\Composer\ComposerPackage;

interface AutoloadConfigInterface
{

    /**
     * The directory where the source files are located.
     *
     * absolute? relative?
     */
    public function getVendorDirectory(): string;

    /**
     * The directory where Strauss copied the files to.
     * absolute.
     */
    public function getTargetDirectory(): string;

    /**
     * The directory containing `composer.json`.
     */
    public function getProjectDirectory(): string;

    public function isClassmapOutput(): bool;

    public function isDryRun(): bool;

    public function isIncludeRootAutoload(): bool;

    public function getNamespacePrefix(): ?string;

    /**
     * @return array<string,ComposerPackage>
     */
    public function getPackagesToCopy(): array;

    /**
     * @return array<string,ComposerPackage>
     */
    public function getPackagesToPrefix(): array;
}
