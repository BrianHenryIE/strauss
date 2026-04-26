<?php

namespace BrianHenryIE\Strauss\Config;

interface MarkFilesExcludedFromChangesConfigInterface
{
    /**
     * @return string[]
     */
    public function getExcludeFilesFromUpdatePackages(): array;

    /**
     * @return string[]
     */
    public function getExcludeFileFromUpdateNamespaces(): array;

    /**
     * @return string[]
     */
    public function getExcludeFilesFromUpdateFilePatterns(): array;
}
