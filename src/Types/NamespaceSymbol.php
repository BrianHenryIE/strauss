<?php

declare(strict_types=1);

/**
 * A PHP namespace discovered in a scanned file or in a package's PSR-0/PSR-4 autoload configuration, which Strauss
 * may rename.
 *
 * Should this be a {@see \PhpParser\Node\Stmt\Namespace_} instead?
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Types;

/**
 * The local name is the whole namespace (see {@see DiscoveredSymbol::__construct()}), and `\` represents the global
 * namespace. Its local replacement is used by each {@see NamespacedSymbol} in it to build that symbol's replacement
 * fully qualified name. The autoloaded flag is set for namespaces read from `autoload` keys, and is never true for
 * the global namespace.
 */
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
