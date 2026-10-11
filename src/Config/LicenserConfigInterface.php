<?php
/**
 * The configuration needed to copy package licence files and annotate modified files' headers with a notice of the
 * change, as licences such as MIT and GPL require.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Config;

/**
 * Extended by {@see \BrianHenryIE\Strauss\Composer\Extra\ReplaceConfigInterface} (so implemented by
 * {@see \BrianHenryIE\Strauss\Composer\Extra\StraussConfig}) and consumed by {@see \BrianHenryIE\Strauss\Pipeline\Licenser}.
 * Provides the vendor and target directories and whether the modified date and author should be added to headers.
 */
interface LicenserConfigInterface
{
    public function isIncludeModifiedDate(): bool;

    public function isIncludeAuthor(): bool;

    /**
     * The directory where Strauss copied the files to.
     * absolute.
     */
    public function getAbsoluteTargetDirectory(): string;

    /**
     * The directory where the source files are located.
     *
     * absolute.
     */
    public function getAbsoluteVendorDirectory(): string;
}
