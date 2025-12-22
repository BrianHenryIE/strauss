<?php

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Files\FileBase;

/**
 * @phpstan-import-type TraitAliasArray from AutoloadAliasInterface
 */
class TraitSymbol extends DiscoveredSymbol implements AutoloadAliasInterface
{
    /**
     * @var string[]
     */
    protected array $uses;

    /**
     * @param string $fqdnClassname
     * @param FileBase $sourceFile
     * @param ?string $namespace
     * @param ?ComposerPackage $composerPackage
     * @param ?string[] $uses
     */
    public function __construct(
        string $fqdnClassname,
        FileBase $sourceFile,
        ?string $namespace = null,
        ?ComposerPackage $composerPackage = null,
        ?array $uses = null
    ) {
        parent::__construct($fqdnClassname, $sourceFile, $namespace ?? '\\', $composerPackage);

        $this->uses = (array) $uses;
    }

    /**
     * @return string[]
     */
    public function getUses(): array
    {
        return $this->uses;
    }

    /**
     * @return TraitAliasArray
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
