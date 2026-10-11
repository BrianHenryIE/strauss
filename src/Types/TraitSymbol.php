<?php
/**
 * A trait definition discovered in a scanned file, which Strauss may prefix, and for which it records what is
 * needed to write an alias for the original trait name in `autoload_aliases.php`.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Files\FileBase;

/**
 * @phpstan-import-type TraitAliasArray from AutoloadAliasInterface
 */
class TraitSymbol extends NamespacedSymbol implements AutoloadAliasInterface
{
    /**
     * @var string[]
     */
    protected array $uses;

    /**
     * @param string           $fqdnClassname   Fully qualified trait name, without leading backslash.
     * @param FileBase         $sourceFile      The file the trait was first discovered in.
     * @param NamespaceSymbol  $namespace       The namespace the trait is declared in (may be the global namespace).
     * @param ?ComposerPackage $composerPackage The package the trait belongs to; null if not from a package.
     * @param ?string[]        $uses            FQDNs of the traits this trait `use`s; null for none.
     */
    public function __construct(
        string $fqdnClassname,
        FileBase $sourceFile,
        NamespaceSymbol $namespace,
        ?ComposerPackage $composerPackage = null,
        ?array $uses = null
    ) {
        parent::__construct($fqdnClassname, $sourceFile, $namespace, $composerPackage);

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
     * In `autoload_aliases.php`, we create aliases for the old trait name by creating a trait for them and `use`ing
     * the renamed trait.
     *
     * ```
     * trait OriginalName {
     *     use UpdatedName;
     * }
     * ```
     *
     * This contains all the methods of the trait and can be used in dev dependencies without renaming their call sites.
     *
     * @see AliasAutoloader::traitTemplate()
     *
     * @return TraitAliasArray
     */
    public function getAutoloadAliasArray(): array
    {
        return array (
            'type' => 'trait',
            'traitname' => $this->getOriginalLocalName(),
            'namespace' => $this->namespace->getOriginalFqdnName(),
            'use' => [$this->getReplacementFqdnName()],
        );
    }
}
