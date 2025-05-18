<?php

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Files\File;

class ClassSymbol extends DiscoveredSymbol implements AutoloadAliasInterface
{
    protected ?string $extends;
    protected bool $isAbstract;
    protected array $interfaces;

    public function __construct(
        string $fqdnClassname,
        File $sourceFile,
        bool $isAbstract = false,
        string $namespace = '\\',
        ?string $extends = null,
        ?array $interfaces = null
    ) {
        parent::__construct($fqdnClassname, $sourceFile, $namespace);

        $this->isAbstract = $isAbstract;
        $this->extends = $extends;
        $this->interfaces = (array) $interfaces;
    }

    public function getExtends(): ?string
    {
        return $this->extends;
    }

    public function getInterfaces(): array
    {
        return $this->interfaces;
    }

    public function isAbstract(): bool
    {
        return $this->isAbstract;
    }

    public function getAutoloadAliasArray(): array
    {
        return array (
            'type' => 'class',
            'classname' => $this->getOriginalLocalName(),
            'namespace' => $this->namespace,
            'extends' => $this->getReplacement(),
            'implements' => $this->interfaces,
        );
    }
}
