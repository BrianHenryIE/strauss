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
     * In `autoload_aliases.php`, we create aliases for the old class name by creating a class that extends the renamed
     * class. This makes the original methods available via the original classname to dev dependencies without updating
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
