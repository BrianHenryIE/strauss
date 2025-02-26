<?php

namespace BrianHenryIE\Strauss\Config;

interface AutoloadConfigInterace
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

    /**
     * The directory containing `composer.json`.
     */
    public function getProjectDirectory(): string;

    public function isClassmapOutput(): bool;

    public function isDryRun(): bool;
}
