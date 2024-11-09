<?php

namespace BrianHenryIE\Strauss\Config;

interface FileCopyScannerConfigInterface
{

    public function getVendorDirectory(): string;

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

    public function isDeleteVendorFiles(): bool;

    public function getTargetDirectory(): string;
}
