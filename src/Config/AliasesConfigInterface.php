<?php

namespace BrianHenryIE\Strauss\Config;

interface AliasesConfigInterface
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

    public function isDryRun(): bool;

    public function isCreateAliases(): bool;

    public function getNamespacePrefix(): ?string;
}
