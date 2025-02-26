<?php

namespace BrianHenryIE\Strauss\Composer\Extra;

interface ReplaceConfigInterface
{

    /**
     * @return string[]
     */
    public function getExcludeFilePatternsFromPrefixing(): array;

    /**
     * @return array<string,string>
     */
    public function getNamespaceReplacementPatterns(): array;

    public function isIncludeModifiedDate(): bool;

    public function isIncludeAuthor(): bool;

    public function getUpdateCallSites(): ?array;
}
