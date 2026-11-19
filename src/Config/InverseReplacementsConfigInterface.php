<?php

/**
 * The configuration needed to add the names symbols had before an earlier run renamed them.
 *
 * @package brianhenryie/strauss
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Config;

use BrianHenryIE\Strauss\Composer\ComposerPackage;

/**
 * Implemented by {@see \BrianHenryIE\Strauss\Composer\Extra\StraussConfig} and consumed by
 * {@see \BrianHenryIE\Strauss\Pipeline\InverseReplacements}.
 */
interface InverseReplacementsConfigInterface
{
    /**
     * Only when prefixing in place do packages still use the names an earlier run renamed.
     */
    public function isTargetDirectoryVendor(): bool;

    /**
     * @return array<string, ComposerPackage> Keyed by package name.
     */
    public function getPackagesToPrefix(): array;
}
