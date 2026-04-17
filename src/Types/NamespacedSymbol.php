<?php
/**
 * This is used so NamespaceSymbol doesn't have a namespace property itself.
 * Objects/classes inheriting from this could just be in the global namespace.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Files\FileBase;

class NamespacedSymbol extends DiscoveredSymbol
{

    protected ?NamespaceSymbol $namespace;

    protected string $fqdnOriginalSymbol;

    public function __construct(
        string $fqdnSymbol,
        FileBase $sourceFile,
        ?NamespaceSymbol $namespace = null
    ) {
        parent::__construct($fqdnSymbol, $sourceFile);

        $this->namespace = $namespace ?? NamespaceSymbol::global();
    }

    public function getOriginalFqdnName(): string
    {
        return $this->namespace->getOriginalSymbol() . '\\' . $this->getOriginalLocalName();
    }

    public function getFqdnReplacement(): string
    {
        return $this->isDoRename()
            ? $this->namespace->getLocalReplacement() . '\\' . $this->getLocalReplacement()
            : $this->fqdnOriginalSymbol;
    }

    public function getNamespace(): NamespaceSymbol
    {
        return $this->namespace;
    }

    public function getNamespaceName(): ?string
    {
        return $this->namespace
            ? $this->namespace->getOriginalSymbol()
            : null; // TODO: should this return `\`?
    }
}
