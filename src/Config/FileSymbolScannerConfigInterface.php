<?php

namespace BrianHenryIE\Strauss\Config;

interface FileSymbolScannerConfigInterface
{
    /**
     * @return string[]
     */
    public function getExcludeNamespacesFromPrefixing(): array;
}
