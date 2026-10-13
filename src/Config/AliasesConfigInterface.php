<?php

/**
 * The configuration needed by the aliases step, which writes `autoload_aliases.php` so code that still references
 * original (unprefixed) class names, e.g. dev dependencies, keeps working after prefixing in the vendor directory.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Config;

/**
 * Implemented by {@see \BrianHenryIE\Strauss\Composer\Extra\StraussConfig} and consumed by
 * {@see \BrianHenryIE\Strauss\Pipeline\Aliases\Aliases}, which reads the vendor directory and namespace prefix;
 * {@see \BrianHenryIE\Strauss\Console\Commands\DependenciesCommand} checks {@see self::isCreateAliases()} before
 * running that step.
 */
interface AliasesConfigInterface
{
    /**
     * The directory where the source files are located.
     *
     * Absolute? Relative?
     */
    public function getAbsoluteVendorDirectory(): string;

    /**
     * The directory where Strauss copied the files to.
     * Absolute? Relative?
     */
    public function getAbsoluteTargetDirectory(): string;

    public function isDryRun(): bool;

    public function isCreateAliases(): bool;

    public function getNamespacePrefix(): ?string;
}
