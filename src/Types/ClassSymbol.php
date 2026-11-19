<?php

/**
 * A class definition discovered in a scanned file, which Strauss may prefix, and for which it records what is
 * needed to write an alias for the original class name in `autoload_aliases.php`.
 *
 * @package brianhenryie/strauss
 */

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
     * @param string          $fqdnClassname Fully qualified class name, without leading backslash.
     * @param FileBase        $sourceFile    The file the class was first discovered in.
     * @param NamespaceSymbol $namespace     The namespace the class is declared in (may be the global namespace).
     * @param bool            $isAbstract    Whether the class is declared `abstract`.
     * @param ?string         $extends       FQDN of the parent class, without leading backslash; null if none.
     * @param string[]        $interfaces    FQDNs of implemented interfaces, without leading backslashes.
     */
    public function __construct(
        string $fqdnClassname,
        FileBase $sourceFile,
        NamespaceSymbol $namespace,
        bool $isAbstract = false,
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
     * In `autoload_aliases.php`, the original class name is aliased to the renamed class with `class_alias()`, so dev
     * dependencies can keep using the original name without updating their call sites. Both names are the same class,
     * so objects created by either satisfy type declarations using either, and final classes can be aliased.
     *
     * @see AliasAutoloader::autoload()
     * @see https://github.com/BrianHenryIE/strauss/issues/363
     *
     * @return ClassAliasArray
     */
    public function getAutoloadAliasArray(): array
    {
        return  [
            'type' => 'class',
            'classname' => $this->getOriginalLocalName(),
            'namespace' => $this->namespace->getOriginalFqdnName(),
            'concrete' => $this->getReplacementFqdnName(),
        ];
    }
}
