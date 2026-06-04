<?php

declare(strict_types=1);

/**
 * Should this be a {@see \PhpParser\Node\Stmt\Namespace_} instead?
 */

namespace BrianHenryIE\Strauss\Types;

class NamespaceSymbol extends DiscoveredSymbol
{
    public function isGlobal(): bool
    {
        return $this->fqdnOriginalSymbol === '\\';
    }

    public function isChangedNamespace(): bool
    {
        return $this->getLocalReplacement() !== $this->getOriginalSymbol();
    }
}
