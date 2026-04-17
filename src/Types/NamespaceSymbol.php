<?php
/**
 * Should this be a {@see \PhpParser\Node\Stmt\Namespace_} instead?
 */

namespace BrianHenryIE\Strauss\Types;

class NamespaceSymbol extends DiscoveredSymbol
{
    protected static NamespaceSymbol $instance;
    public static function global(): NamespaceSymbol
    {
        if (!isset(self::$instance)) {
            self::$instance = new NamespaceSymbol('\\');
            self::$instance->setDoRename(false);
        }
        return self::$instance;
    }

    public function isGlobal(): bool
    {
        return $this->fqdnOriginalSymbol === '\\';
    }

    public function isChangedNamespace(): bool
    {
        return $this->getLocalReplacement() !== $this->getOriginalSymbol();
    }
}
