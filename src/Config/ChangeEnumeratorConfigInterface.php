<?php
/**
 * The configuration needed to determine the replacement name for each discovered symbol: the namespace, classmap,
 * function and constant prefixes, plus any explicit namespace replacement patterns.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Config;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;

/**
 * Implemented by {@see StraussConfig} and consumed by {@see \BrianHenryIE\Strauss\Pipeline\ChangeEnumerator}, which
 * sets each symbol's replacement name from the prefixes, the namespace replacement patterns and the excluded
 * namespaces, and stops function names listed in `exclude_from_string_rename` being replaced inside strings.
 *
 * @see StraussConfig
 */
interface ChangeEnumeratorConfigInterface
{
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
     * @return array<string, string>
     */
    public function getNamespaceReplacementPatterns(): array;

    public function getNamespacePrefix(): ?string;

    public function getClassmapPrefix(): ?string;

    /**
     * @return array<string,ComposerPackage>
     */
    public function getPackagesToPrefix(): array;

    /**
     * The prefix to use for global functions. Null if none should be used.
     */
    public function getFunctionsPrefix(): ?string;

    public function getConstantsPrefix(): ?string;

    /**
     * Global function names which should be prefixed where called, but not where the name appears inside a string.
     *
     * @return string[]
     */
    public function getExcludeFunctionsFromStringRenaming(): array;
}
