<?php
/**
 * After files are modified, an `autoload_aliases.php` file is created so the previous classnames continue to
 * work. Autoloading only applies to classes, interfaces and traits (enums?!), who this interface is applied to.
 *
 * @see Aliases
 */

namespace BrianHenryIE\Strauss\Types;

interface AutoloadAliasInterface
{
    public function getAutoloadAliasArray(): array;
}
