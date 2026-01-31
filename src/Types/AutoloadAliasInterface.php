<?php
/**
 * After files are modified, an `autoload_aliases.php` file is created so the previous classnames continue to
 * work. Autoloading only applies to classes, interfaces and traits (enums?!), who this interface is applied to.
 *
 * @see \BrianHenryIE\Strauss\Pipeline\Aliases\Aliases
 */

namespace BrianHenryIE\Strauss\Types;

/**
 * @phpstan-type ClassAliasArray array{'type':'class',isabstract:bool,classname:string,namespace?:string|null,extends:string,implements:array<string>}
 * @phpstan-type InterfaceAliasArray array{'type':'interface',interfacename:string,namespace?:string|null,extends:array<string>}
 * @phpstan-type TraitAliasArray array{'type':'trait',traitname:string,namespace?:string|null,use:array<string>}
 */
interface AutoloadAliasInterface
{
    /**
     * @return ClassAliasArray|InterfaceAliasArray|TraitAliasArray
     */
    public function getAutoloadAliasArray(): array;
}
