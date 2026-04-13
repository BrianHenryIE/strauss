<?php

namespace BrianHenryIE\Strauss\Config;

interface FileEnumeratorConfig
{

    public function getAbsoluteVendorDirectory(): string;

    public function getAbsoluteTargetDirectory(): string;

    public function getRelativeTargetDirectory(): string;

    /** @return string[] */
    public function getExcludeNamespacesFromCopy(): array;

    /** @return string[] */
    public function getExcludePackagesFromCopy(): array;

    /** @return string[] */
    public function getExcludeFilePatternsFromCopy(): array;
}
