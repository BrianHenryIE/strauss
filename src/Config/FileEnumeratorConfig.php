<?php

namespace BrianHenryIE\Strauss\Config;

interface FileEnumeratorConfig
{

    public function getVendorDirectory(): string;

    public function getAbsoluteTargetDirectory(): string;

    /** @return string[] */
    public function getExcludeNamespacesFromCopy(): array;

    /** @return string[] */
    public function getExcludePackagesFromCopy(): array;

    /** @return string[] */
    public function getExcludeFilePatternsFromCopy(): array;
}
