<?php

namespace BrianHenryIE\Strauss\Config;

interface PrefixerConfigInterface
{

    public function getTargetDirectory(): string;

    public function getNamespacePrefix(): string;

    public function getClassmapPrefix(): string;

    public function getConstantsPrefix(): ?string;

    public function getExcludePackagesFromPrefixing(): array;

    public function getExcludeNamespacesFromPrefixing(): array;

    public function getExcludeFilePatternsFromPrefixing(): array;
}
