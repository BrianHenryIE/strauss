<?php

/**
 * An interface definition discovered in a scanned file, which Strauss may prefix, and for which it records what is
 * needed to write an alias for the original interface name in `autoload_aliases.php`.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Files\FileBase;

/**
 * @phpstan-import-type InterfaceAliasArray from AutoloadAliasInterface
 */
class InterfaceSymbol extends NamespacedSymbol implements AutoloadAliasInterface
{
    /**
     * @var string[]
     */
    protected array $extends;

    /**
     * @param string           $fqdnClassname Fully qualified interface name, without leading backslash.
     * @param FileBase         $sourceFile    The file the interface was first discovered in.
     * @param NamespaceSymbol  $namespace     The namespace the interface is declared in (may be the global namespace).
     * @param ?ComposerPackage $package       The package the interface belongs to; null if not from a package.
     * @param string[]         $extends       FQDNs of the interfaces this interface extends.
     */
    public function __construct(
        string $fqdnClassname,
        FileBase $sourceFile,
        NamespaceSymbol $namespace,
        ?ComposerPackage $package = null,
        array $extends = []
    ) {
        parent::__construct($fqdnClassname, $sourceFile, $namespace, $package);

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
     * In `autoload_aliases.php`, the original interface name is aliased to the renamed interface with `class_alias()`,
     * so dev dependencies can keep using the original name without updating their call sites. Both names are the same
     * interface, so classes implementing either satisfy type declarations using either.
     *
     * @see AliasAutoloader::autoload()
     * @see https://github.com/BrianHenryIE/strauss/issues/363
     *
     * @return InterfaceAliasArray
     */
    public function getAutoloadAliasArray(): array
    {
        return  [
            'type' => 'interface',
            'interfacename' => $this->getOriginalLocalName(),
            'namespace' => $this->namespace->getOriginalFqdnName(),
            'concrete' => $this->getReplacementFqdnName(),
        ];
    }
}
