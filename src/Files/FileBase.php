<?php
/**
 * The interface pipeline steps use for every file Strauss handles, whether a project file, a
 * `vendor/composer` autoload file, or a file from a dependency.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Files;

use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;

/**
 * Implemented by {@see File} and {@see FileWithDependency}. Declares accessors for the source, target and
 * vendor-relative paths, the copy/prefix/update/delete flags that pipeline steps set and later read, and the
 * {@see DiscoveredSymbols} defined in the file.
 */
interface FileBase
{

    public function getSourcePath(): string;

    public function getTargetAbsolutePath(): string;

    public function setTargetAbsolutePath(string $absoluteTargetPath): void;

    public function getVendorRelativePath(): string;

    public function isPhpFile(): bool;

    public function isAutoloaded(): bool;
    public function setIsAutoloaded(bool $isAutoloaded): void;

    public function setDoCopy(bool $doCopy): void;

    public function isDoCopy(): bool;

    public function setDoPrefix(bool $doPrefix): void;

    public function isDoPrefix(): bool;

    /**
     * Used to mark files that should be deleted after moving when `delete_vendor_files` is enabled.
     * Used to mark files that are symlinked as not-to-be-deleted.
     *
     * @param bool $doDelete True to delete the source file after copying; false to keep it.
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

    public function setDoUpdate(bool $doUpdate): void;

    public function getDoUpdate(): bool;

    public function setDidUpdate(): void;

    public function addDiscoveredSymbol(DiscoveredSymbol $symbol): void;

    public function getDiscoveredSymbols(): DiscoveredSymbols;

    public function getNamespaces(): DiscoveredSymbols;
}
