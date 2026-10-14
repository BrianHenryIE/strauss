<?php

/**
 * A constant, declared with `const` or `define()`, discovered in a scanned file and which Strauss may prefix.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Types;

/**
 * Adds no behaviour to {@see NamespacedSymbol}. The distinct type lets {@see DiscoveredSymbols} file constants in
 * their own bucket (it routes on the concrete class) and lets {@see \BrianHenryIE\Strauss\Pipeline\ChangeEnumerator}
 * set the replacement to the configured `constant_prefix` followed by the original local name.
 */
class ConstantSymbol extends NamespacedSymbol {}
