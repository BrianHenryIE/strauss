<?php

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Files\FileBase;

/**
 * @phpstan-import-type InterfaceAliasArray from AutoloadAliasInterface
 */
class InterfaceSymbol extends DiscoveredSymbol implements AutoloadAliasInterface
{
    /**
     * @var string[]
     */
    protected array $extends;

    /**
     * @param string $fqdnClassname
     * @param FileBase $sourceFile
     * @param ?string $namespace
     * @param ?ComposerPackage $package
     * @param string[] $extends
     */
    public function __construct(
        string $fqdnClassname,
        FileBase $sourceFile,
        ?string $namespace = null,
        ?ComposerPackage $package = null,
        array $extends = []
    ) {
        parent::__construct($fqdnClassname, $sourceFile, $namespace ?? '\\', $package);

        $this->extends = $extends;
    }

    /**
     * @return string[]
     */
    public function getExtends(): array
    {
        return $this->extends;
    }

    /**
     * @return InterfaceAliasArray
     */
    public function getAutoloadAliasArray(): array
    {
        return array (
            'type' => 'interface',
            'interfacename' => $this->getOriginalLocalName(),
            'namespace' => $this->namespace,
            'extends' => [$this->getReplacement()] + $this->getExtends(),
        );
    }
}
