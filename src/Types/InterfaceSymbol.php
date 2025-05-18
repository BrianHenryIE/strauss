<?php

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Files\File;

class InterfaceSymbol extends DiscoveredSymbol implements AutoloadAliasInterface
{
    protected array $extends;

    public function __construct(
        string $fqdnClassname,
        File $sourceFile,
        ?string $namespace = null,
        ?array $extends = null
    ) {
        parent::__construct($fqdnClassname, $sourceFile, $namespace);

        $this->extends = (array) $extends;
    }

    public function getExtends(): array
    {
        return $this->extends;
    }

    /**
     * @return array{type:string,interfacename:string,namespace:string,extends:array<string>}
     */
    public function getAutoloadAliasArray(): array
    {
        return array (
            'type' => 'interface',
            'interfacename' => $this->getOriginalLocalName(),
            'namespace' => $this->namespace,
            'extends' => [$this->getReplacement()] + $this->extends,
        );
    }
}
