<?php

namespace BrianHenryIE\Strauss\Config;

interface AliasesConfigInterace
{

    /**
     * The directory where the source files are located.
     *
     * absolute? relative?
     */
    public function getVendorDirectory(): string;

    /**
     * The directory where Strauss copied the files to.
     * absolute? relative?
     */
    public function getTargetDirectory(): string;
}
