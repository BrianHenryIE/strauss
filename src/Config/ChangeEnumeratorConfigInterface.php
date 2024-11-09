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
     * @return array<string, string>
     */
    public function getNamespaceReplacementPatterns(): array;

    public function getNamespacePrefix(): ?string;

    public function getClassmapPrefix(): ?string;
}
