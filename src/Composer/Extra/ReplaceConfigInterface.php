<?php

/**
 * The configuration needed by the `replace` command, which renames a namespace in place in the project's own files
 * rather than copying and prefixing vendor packages.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Composer\Extra;

use BrianHenryIE\Strauss\Config\AutoloadFilesEnumeratorConfigInterface;
use BrianHenryIE\Strauss\Config\ChangeEnumeratorConfigInterface;
use BrianHenryIE\Strauss\Config\FileEnumeratorConfig;
use BrianHenryIE\Strauss\Config\FileSymbolScannerConfigInterface;
use BrianHenryIE\Strauss\Config\LicenserConfigInterface;
use BrianHenryIE\Strauss\Config\PrefixerConfigInterface;

/**
 * Combines the per-step interfaces for the steps {@see \BrianHenryIE\Strauss\Console\Commands\ReplaceCommand} runs
 * (file enumeration, symbol scanning, autoloaded file enumeration, change enumeration, prefixing and licensing) and adds
 * {@see self::getUpdateCallSites()}, the files and directories to edit. Implemented by {@see StraussConfig}, which the
 * command populates from its `--from`, `--to` and `--paths` options.
 */
interface ReplaceConfigInterface extends
    FileEnumeratorConfig,
    FileSymbolScannerConfigInterface,
    AutoloadFilesEnumeratorConfigInterface,
    ChangeEnumeratorConfigInterface,
    PrefixerConfigInterface,
    LicenserConfigInterface
{
    /**
     * @return string[]
     */
    public function getExcludeFilePatternsFromPrefixing(): array;

    /**
     * @return array<string,string>
     */
    public function getNamespaceReplacementPatterns(): array;

    public function isIncludeModifiedDate(): bool;

    public function isIncludeAuthor(): bool;

    /**
     * @return string[]|null
     */
    public function getUpdateCallSites(): ?array;
}
