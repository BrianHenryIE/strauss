<?php

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Files\File;

class TraitSymbol extends DiscoveredSymbol implements AutoloadAliasInterface
{
    protected array $uses;

    public function __construct(
        string $fqdnClassname,
        File $sourceFile,
        ?string $namespace = null,
        ?array $uses = null
    ) {
        parent::__construct($fqdnClassname, $sourceFile, $namespace);

        $this->uses = (array) $uses;
    }

    public function getUses(): array
    {
        return $this->uses;
    }

    public function getAutoloadAliasArray(): array
    {
        return array (
            'type' => 'trait',
            'traitname' => $this->getOriginalLocalName(),
            'namespace' => $this->namespace,
            'use' => [$this->getReplacement()],
        );
    }
}
