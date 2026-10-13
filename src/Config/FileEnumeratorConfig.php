<?php

/**
 * The configuration needed to build the list of files belonging to each Composer package that Strauss processes.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Config;

/**
 * Implemented by {@see \BrianHenryIE\Strauss\Composer\Extra\StraussConfig} and consumed by
 * {@see \BrianHenryIE\Strauss\Pipeline\FileEnumerator}. Provides the project, vendor and target paths, the
 * `exclude_from_copy` rules, and whether git-ignored and `export-ignore` files should be skipped.
 */
interface FileEnumeratorConfig
{
    public function getProjectAbsolutePath(): string;
    public function getAbsoluteVendorDirectory(): string;

    public function getAbsoluteTargetDirectory(): string;

    public function getRelativeTargetDirectory(): string;

    /**
     * @return string[]
     */
    public function getExcludeNamespacesFromCopy(): array;

    /**
     * @return string[]
     */
    public function getExcludePackagesFromCopy(): array;

    /**
     * @return string[]
     */
    public function getExcludeFilePatternsFromCopy(): array;

    /**
     * Whether to skip `.git`, `.gitignore`-matched and `.gitattributes`.`[].export-ignore` files when
     * enumerating each package's files.
     */
    public function isExcludeGitFiles(): bool;
}
