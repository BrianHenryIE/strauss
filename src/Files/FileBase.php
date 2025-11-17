<?php

namespace BrianHenryIE\Strauss\Files;

use BrianHenryIE\Strauss\Types\DiscoveredSymbol;

interface FileBase
{

    public function getSourcePath(): string;

    public function getAbsoluteTargetPath(): string;

    public function setAbsoluteTargetPath(string $absoluteTargetPath): void;

    public function isPhpFile(): bool;

    public function setDoCopy(bool $doCopy): void;

    public function isDoCopy(): bool;

    public function setDoPrefix(bool $doPrefix): void;

    public function isDoPrefix(): bool;

    /**
     * Used to mark files that should be deleted after moving when `delete_vendor_files` is enabled.
     * Used to mark files that are symlinked as not-to-be-deleted.
     *
     * @param bool $doDelete
     *
     * @return void
     */
    public function setDoDelete(bool $doDelete): void;

    /**
     * Should file be deleted?
     *
     * NB: Also respect the "delete_vendor_files"|"delete_vendor_packages" settings.
     */
    public function isDoDelete(): bool;

    public function setDidDelete(bool $didDelete): void;

    public function getDidDelete(): bool;

    public function addDiscoveredSymbol(DiscoveredSymbol $symbol): void;

    /**
     * @return array<string, DiscoveredSymbol> The discovered symbols in the file, indexed by their original string name.
     */
    public function getDiscoveredSymbols(): array;
}
