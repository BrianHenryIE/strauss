<?php

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Files\File;

class TraitSymbol extends DiscoveredSymbol implements AutoloadAliasInterface
{
    protected array $uses;

    public function __construct(
        string $fqdnClassname,
        File $sourceFile,
        ?string $namespace = null,
        ?ComposerPackage $composerPackage = null,
        ?array $uses = null
    ) {
        parent::__construct($fqdnClassname, $sourceFile, $namespace, $composerPackage);

        $this->uses = (array) $uses;
    }

    public function getUses(): array
    {
        return $this->uses;
    }

    /**
     * @return array{type:string,traitname:string,namespace:string,use:array<string>}
     */
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
