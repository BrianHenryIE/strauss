<?php
/**
 * Should this be a {@see \PhpParser\Node\Stmt\Namespace_} instead?
 */

namespace BrianHenryIE\Strauss\Types;

class NamespaceSymbol extends DiscoveredSymbol
{
    public function isChangedNamespace(): bool
    {
        return $this->getReplacement() !== $this->getOriginalSymbol();
    }
}
