<?php

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Pipeline\Prefixer;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;

class PrefixerParseErrorHarness extends Prefixer
{
    /**
     * @param array<string,string> $functionReplacementMap
     */
    public function callReplaceFunctionsBatch(string $contents, array $functionReplacementMap): string
    {
        return $this->replaceFunctionsBatch($contents, $functionReplacementMap);
    }

    /**
     * @param array<string,NamespaceSymbol> $namespaceSymbols
     */
    public function callReplaceConstFetchNamespacesByMap(array $namespaceSymbols, string $contents): string
    {
        return $this->replaceConstFetchNamespacesByMap($namespaceSymbols, $contents);
    }

    /**
     * @param NamespaceSymbol[] $namespaceSymbols
     */
    public function callPrepareRelativeNamespaces(string $contents, array $namespaceSymbols): string
    {
        return $this->prepareRelativeNamespaces($contents, $namespaceSymbols);
    }
}
