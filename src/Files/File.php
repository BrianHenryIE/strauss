<?php
/**
 * A file without a dependency means the project src files and the vendor/composer autoload files.
 */

namespace BrianHenryIE\Strauss\Files;

use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;

class File implements FileBase
{
    /**
     * @var string The absolute path to the file on disk.
     */
    protected string $sourceAbsolutePath;

    protected string $vendorRelativePath;

    /**
     * Should this file be copied to the target directory?
     */
    protected bool $doCopy = true;

    /**
     * Should this file be deleted from the source directory?
     *
     * `null` means defer to the package's `isDelete` setting.
     */
    protected ?bool $doDelete = false;

    protected DiscoveredSymbols $discoveredSymbols;

    protected string $targetAbsolutePath;

    protected bool $didDelete = false;

    protected bool $doPrefix = true;

    public function __construct(
        string $sourceAbsolutePath,
        string $vendorRelativePath,
        string $targetAbsolutePath
    ) {
        $this->discoveredSymbols = new DiscoveredSymbols();

        $this->sourceAbsolutePath = $sourceAbsolutePath;
        $this->vendorRelativePath = $vendorRelativePath;
        $this->targetAbsolutePath = $targetAbsolutePath;
    }

    public function getSourcePath(): string
    {
        return $this->sourceAbsolutePath;
    }

    public function isPhpFile(): bool
    {
        return substr($this->sourceAbsolutePath, -4) === '.php';
    }

    /**
     * Some combination of file copy exclusions and vendor-dir == target-dir
     *
     * @param bool $doCopy
     *
     * @return void
     */
    public function setDoCopy(bool $doCopy): void
    {
        $this->doCopy = $doCopy;
    }
    public function isDoCopy(): bool
    {
        return $this->doCopy;
    }

    public function isAutoloaded(): bool
    {
        return false;
    }

    /**
     * Should symbols discovered in this file be prefixed. (i.e. class definitions etc., not usages)
     */
    public function setDoPrefix(bool $doPrefix): void
    {
        $this->doPrefix = $doPrefix;
    }

    /**
     * Is this correct? Is there ever a time that NO changes should be made to a file? I.e. another file would have its
     * namespace changed and it needs to be updated throughout.
     *
     * Is this really a Symbol level function?
     */
    public function isDoPrefix(): bool
    {
        return $this->doPrefix;
    }

    /**
     * For marking moved files to be deleted when delete_vendor_files is enabled. (should that be deprecated?)
     * For marking files that are symlinked as not-to-be-deleted.
     *
     * @param bool $doDelete
     */
    public function setDoDelete(bool $doDelete): void
    {
        $this->doDelete = $doDelete;
    }

    /**
     * Should file be deleted?
     *
     * NB: Also respect the "delete_vendor_files"|"delete_vendor_packages" settings.
     */
    public function isDoDelete(): bool
    {
        return (bool) $this->doDelete;
    }

    /**
     * @see Cleanup::doIsDeleteVendorFiles()
     */
    public function setDidDelete(bool $didDelete): void
    {
        $this->didDelete = $didDelete;
    }

    public function getDidDelete(): bool
    {
        return $this->didDelete;
    }

    public function addDiscoveredSymbol(DiscoveredSymbol $symbol): void
    {
        $this->discoveredSymbols->add($symbol);
    }

    public function getDiscoveredSymbols(): DiscoveredSymbols
    {
        return $this->discoveredSymbols;
    }

    public function setTargetAbsolutePath(string $targetAbsolutePath): void
    {
        $this->targetAbsolutePath = $targetAbsolutePath;
    }

    /**
     * The target path to (maybe) copy the file to, and the target path to perform replacements in (which may be the
     * original path).
     */
    public function getTargetAbsolutePath(): string
    {
        return $this->targetAbsolutePath;
    }

    protected bool $didUpdate = false;

    public function setDidUpdate(): void
    {
        $this->didUpdate = true;
    }

    public function getDidUpdate(): bool
    {
        return $this->didUpdate;
    }

    protected bool $doUpdate = true;

    public function setDoUpdate(bool $doUpdate): void
    {
        $this->doUpdate = $doUpdate;
    }

    public function getDoUpdate(): bool
    {
        return $this->doUpdate;
    }

    public function getVendorRelativePath(): string
    {
        return $this->vendorRelativePath;
    }

    public function getNamespaces(): DiscoveredSymbols
    {
        return $this->discoveredSymbols->getNamespaces();
    }
}
