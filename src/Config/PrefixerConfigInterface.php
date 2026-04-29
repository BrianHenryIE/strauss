<?php

namespace BrianHenryIE\Strauss\Config;

interface PrefixerConfigInterface
{
    public function getAbsoluteVendorDirectory(): string;

    public function getAbsoluteTargetDirectory(): string;

    public function isTargetDirectoryVendor(): bool;

    public function getNamespacePrefix(): ?string;

    public function getClassmapPrefix(): ?string;

    public function getConstantsPrefix(): ?string;

    /** @return string[] */
    public function getExcludePackagesFromPrefixing(): array;

    /** @return string[] */
    public function getExcludeNamespacesFromPrefixing(): array;

    /** @return string[] */
    public function getExcludeFilePatternsFromPrefixing(): array;

    public function isDryRun(): bool;
}
