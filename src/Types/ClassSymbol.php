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

    /**
     * @return array{type:string,classname:string,isabstract:bool,namespace:string,extends:string,implements:array<string>}
     */
    public function getAutoloadAliasArray(): array
    {
        return array (
            'type' => 'class',
            'classname' => $this->getOriginalLocalName(),
            'isabstract' => $this->isAbstract,
            'namespace' => $this->namespace,
            'extends' => $this->getReplacement(),
            'implements' => $this->interfaces,
        );
    }
}
