<?php

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
     * @param string $fqdnClassname
     * @param FileBase $sourceFile
     * @param ?NamespaceSymbol $namespace
     * @param ?ComposerPackage $package
     * @param string[] $extends
     */
    public function __construct(
        string $fqdnClassname,
        FileBase $sourceFile,
        ?NamespaceSymbol $namespace = null,
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
     * In `autoload_aliases.php`, interfaces work by extending the new interface:
     *
     * ```
     * namespace OldNamespace;
     * interface OriginalInterfaceName extends ReplacementFqdn\InterfaceName {}
     * ```
     *
     * With this, dev dependencies can continue to use the old fqdn interface without updating their call sites.
     *
     * @see AliasAutoloader::interfaceTemplate()
     *
     * @return InterfaceAliasArray
     */
    public function getAutoloadAliasArray(): array
    {
        return array (
            'type' => 'interface',
            'interfacename' => $this->getOriginalLocalName(),
            'namespace' => $this->namespace->getOriginalSymbol(),
            'extends' => [$this->getReplacementFqdnName()] + $this->getExtends(),
        );
    }
}
