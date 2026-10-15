<?php

/**
 * A function definition discovered in a scanned file, which Strauss may prefix.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Types;

/**
 * Adds no behaviour to {@see NamespacedSymbol}. The distinct type lets {@see DiscoveredSymbols} file functions in
 * their own bucket so {@see \BrianHenryIE\Strauss\Pipeline\ChangeEnumerator} can give only global functions the
 * functions prefix (functions in a namespace are renamed with their namespace) and can disable in-string
 * replacement for names listed in `exclude_functions_from_string_renaming`.
 */
class FunctionSymbol extends NamespacedSymbol {}
