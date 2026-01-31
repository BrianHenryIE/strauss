<?php

namespace BrianHenryIE\Strauss\Composer\Extra;

use BrianHenryIE\Strauss\Config\AutoloadFilesEnumeratorConfigInterface;
use BrianHenryIE\Strauss\Config\ChangeEnumeratorConfigInterface;
use BrianHenryIE\Strauss\Config\FileEnumeratorConfig;
use BrianHenryIE\Strauss\Config\FileSymbolScannerConfigInterface;
use BrianHenryIE\Strauss\Config\LicenserConfigInterface;
use BrianHenryIE\Strauss\Config\PrefixerConfigInterface;

interface ReplaceConfigInterface extends
    FileEnumeratorConfig,
    FileSymbolScannerConfigInterface,
    AutoloadFilesEnumeratorConfigInterface,
    ChangeEnumeratorConfigInterface,
    PrefixerConfigInterface,
    LicenserConfigInterface
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

    /**
     * @return string[]|null
     */
    public function getUpdateCallSites(): ?array;
}
