<?php

/**
 * The configuration which says whether Composer's `optimize-autoloader` behaviour should be used when the
 * autoloader is regenerated.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Config;

/**
 * Implemented by {@see \BrianHenryIE\Strauss\Composer\Extra\StraussConfig}. It is optional: both
 * {@see \BrianHenryIE\Strauss\Pipeline\Autoload\DumpAutoload} and {@see \BrianHenryIE\Strauss\Pipeline\Cleanup\Cleanup}
 * check `instanceof` this interface before calling {@see self::isOptimizeAutoloader()}.
 */
interface OptimizeAutoloaderConfigInterface
{
    public function isOptimizeAutoloader(): bool;
}
