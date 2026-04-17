<?php
/**
 * Should this be a {@see \PhpParser\Node\Stmt\Namespace_} instead?
 */

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Files\File;

class NamespaceSymbol extends DiscoveredSymbol
{
    protected static NamespaceSymbol $instance;
    public static function global(): NamespaceSymbol
    {
        if (!isset(self::$instance)) {
            $file = new File(__FILE__, __FILE__, __FILE__);
            self::$instance = new NamespaceSymbol('\\', $file);
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
