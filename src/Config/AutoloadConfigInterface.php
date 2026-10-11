<?php
/**
 * The configuration needed to generate the autoloader for the prefixed files and to include it from the project's
 * `vendor/autoload.php`.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Config;

use BrianHenryIE\Strauss\Composer\ComposerPackage;

/**
 * Implemented by {@see \BrianHenryIE\Strauss\Composer\Extra\StraussConfig} and consumed by
 * {@see \BrianHenryIE\Strauss\Pipeline\Autoload}, {@see \BrianHenryIE\Strauss\Pipeline\Autoload\DumpAutoload} and
 * {@see \BrianHenryIE\Strauss\Pipeline\Autoload\VendorComposerAutoload}. Exposes the vendor, target and project paths,
 * output options (classmap, root autoload, dry run) and the {@see ComposerPackage} lists to copy and to prefix.
 */
interface AutoloadConfigInterface
{

    /**
     * The directory where the source files are located.
     *
     * absolute? relative?
     */
    public function getAbsoluteVendorDirectory(): string;

    /**
     * The directory where Strauss copied the files to.
     * absolute.
     */
    public function getAbsoluteTargetDirectory(): string;

    public function getRelativeTargetDirectory(): string;

    public function isTargetDirectoryVendor(): bool;

    /**
     * The directory containing `composer.json`.
     */
    public function getProjectAbsolutePath(): string;

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
