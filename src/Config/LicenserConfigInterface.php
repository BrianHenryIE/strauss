<?php

namespace BrianHenryIE\Strauss\Config;

interface LicenserConfigInterface
{
    public function isIncludeModifiedDate(): bool;

    public function isIncludeAuthor(): bool;

    /**
     * The directory where Strauss copied the files to.
     * absolute.
     */
    public function getTargetDirectory(): string;

    /**
     * The directory where the source files are located.
     *
     * absolute.
     */
    public function getVendorDirectory(): string;
}
