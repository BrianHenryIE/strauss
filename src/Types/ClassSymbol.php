<?php

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Files\FileBase;

/**
 * @phpstan-import-type ClassAliasArray from AutoloadAliasInterface
 */
class ClassSymbol extends NamespacedSymbol implements AutoloadAliasInterface
{
    protected ?string $extends;
    protected bool $isAbstract;

    /**
     * @var string[]
     */
    protected array $interfaces;

    /**
     * @param string $fqdnClassname
     * @param FileBase $sourceFile
     * @param bool $isAbstract
     * @param ?NamespaceSymbol $namespace
     * @param ?string $extends
     * @param string[] $interfaces
     */
    public function __construct(
        string $fqdnClassname,
        FileBase $sourceFile,
        bool $isAbstract = false,
        ?NamespaceSymbol $namespace = null,
        ?string $extends = null,
        array $interfaces = []
    ) {
        parent::__construct($fqdnClassname, $sourceFile, $namespace);

        $this->isAbstract = $isAbstract;
        $this->extends = $extends;
        $this->interfaces = $interfaces;
    }

    public function getExtends(): ?string
    {
        return $this->extends;
    }

    /**
     * @return string[]
     */
    public function getInterfaces(): array
    {
        return $this->interfaces;
    }

    public function isAbstract(): bool
    {
        return $this->isAbstract;
    }

    /**
     * In `autoload_aliases.php`, we create aliases for the old class name by creating a class that extends the renamed
     * class. This makes the original methods avaiable via the original classname to dev dependencies without updating
     * their call sites.
     *
     * ```
     * class OriginalName extends NewName {}
     * ```
     *
     * @see AliasAutoloader::classTemplate()
     *
     * @return ClassAliasArray
     */
    public function getAutoloadAliasArray(): array
    {
        return array (
            'type' => 'class',
            'classname' => $this->getOriginalLocalName(),
            'isabstract' => $this->isAbstract,
            'namespace' => $this->namespace->getOriginalFqdnName(),
            'extends' => $this->getReplacementFqdnName(),
            'implements' => $this->interfaces,
        );
    }
}
