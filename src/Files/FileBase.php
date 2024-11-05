<?php

namespace BrianHenryIE\Strauss\Files;

use BrianHenryIE\Strauss\DiscoveredSymbol;

interface FileBase
{

    public function getSourcePath(string $relativeTo = ''): string;

    public function isPhpFile(): bool;

    public function addNamespace(string $namespaceName): void;

    public function addClass(string $className): void;

    public function addTrait(string $traitName): void;

    public function setDoCopy(bool $doCopy): void;

    public function isDoCopy(): bool;

    public function setDoPrefix(bool $doPrefix): void;

    public function isDoPrefix(): bool;

    /**
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

    public function getContents(): string;
}
