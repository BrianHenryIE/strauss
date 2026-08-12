<?php

declare(strict_types=1);

/**
 * Should this be a {@see \PhpParser\Node\Stmt\Namespace_} instead?
 */

namespace BrianHenryIE\Strauss\Types;

class NamespaceSymbol extends DiscoveredSymbol
{
    protected bool $isAutoloaded = false;

    public function isGlobal(): bool
    {
        return $this->fqdnOriginalSymbol === '\\';
    }

    public function isChangedNamespace(): bool
    {
        return $this->getLocalReplacement() !== $this->getOriginalFqdnName();
    }

    public function setIsAutoloaded(bool $isAutoloaded): void
    {
        $this->isAutoloaded = $isAutoloaded;
    }

    public function isAutoloaded(): bool
    {
        return $this->isAutoloaded && $this->localOriginalSymbol !== '\\';
    }
}
