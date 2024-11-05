<?php

namespace BrianHenryIE\Strauss\Composer\Extra;

interface StraussConfigInterface
{

    /**
     * `target_directory` will always be returned without a leading slash (i.e. relative) and with a trailing slash.
     */
    public function getTargetDirectory(): string;

    public function getVendorDirectory(): string;

    /**
     * @return string
     */
    public function getNamespacePrefix(): string;

    public function getClassmapPrefix(): string;

    public function getConstantsPrefix(): ?string;

    /**
     * List of files and directories to update call sites in. Empty to disable. Null infers from the project's autoload key.
     *
     * @return string[]|null
     */
    public function getUpdateCallSites(): ?array;

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

    /**
     * When prefixing, do not prefix these packages (which have been copied).
     *
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

    /**
     * @return array{}|array<string, array{files?:array<string>,classmap?:array<string>,"psr-4":array<string|array<string>>}> $overrideAutoload Dictionary of package name: autoload rules.
     */
    public function getOverrideAutoload(): array;

    public function isDeleteVendorFiles(): bool;

    public function isDeleteVendorPackages(): bool;

    /**
     * @return string[]
     */
    public function getPackages(): array;

    public function isClassmapOutput(): bool;

    /**
     * @return array<string,string>
     */
    public function getNamespaceReplacementPatterns(): array;

    public function isIncludeModifiedDate(): bool;

    public function isIncludeAuthor(): bool;
}
