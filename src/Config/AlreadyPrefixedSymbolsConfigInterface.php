<?php

/**
 * The configuration needed to recognise symbols which were prefixed by an earlier run of Strauss.
 *
 * @package brianhenryie/strauss
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Config;

use BrianHenryIE\Strauss\Composer\ComposerPackage;

/**
 * Implemented by {@see \BrianHenryIE\Strauss\Composer\Extra\StraussConfig} and consumed by
 * {@see \BrianHenryIE\Strauss\Pipeline\AlreadyPrefixedSymbols}.
 */
interface AlreadyPrefixedSymbolsConfigInterface
{
    public function isTargetDirectoryVendor(): bool;

    public function getNamespacePrefix(): ?string;

    /**
     * @return array<string,ComposerPackage>
     */
    public function getPackagesToPrefix(): array;
}
