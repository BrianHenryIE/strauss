<?php

namespace BrianHenryIE\Strauss\Config;

interface FileEnumeratorConfig
{

    public function getVendorDirectory(): string;

    public function getExcludeNamespacesFromCopy(): array;

    public function getExcludePackagesFromCopy(): array;

    public function getExcludeFilePatternsFromCopy(): array;
}
