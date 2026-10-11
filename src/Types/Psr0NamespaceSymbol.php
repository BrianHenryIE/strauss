<?php
/**
 * A namespace read from a package's `autoload.psr-0` key, i.e. one whose classes may use underscore-separated
 * pseudo-namespaces rather than PHP namespaces.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Types;

/**
 * A marker subclass of {@see NamespaceSymbol} with no behaviour of its own, created in
 * {@see \BrianHenryIE\Strauss\Console\Commands\DependenciesCommand::enumeratePsrNamespaces()} in place of a plain
 * {@see NamespaceSymbol} for `psr-0` keys. {@see DiscoveredSymbols} stores it in the namespace bucket alongside
 * PSR-4 namespaces.
 */
class Psr0NamespaceSymbol extends NamespaceSymbol
{
}
