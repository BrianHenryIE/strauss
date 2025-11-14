<?php

namespace BrianHenryIE\Strauss\Config;

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

    public function getPackagesToPrefix(): array;

    /**
     * The prefix to use for global functions. Null if none should be used.
     */
    public function getFunctionsPrefix(): ?string;
}
