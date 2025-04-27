<?php

namespace BrianHenryIE\Strauss\Files;

use BrianHenryIE\Strauss\Types\DiscoveredSymbol;

class File implements FileBase
{
    /**
     * @var string The absolute path to the file on disk.
     */
    protected string $sourceAbsolutePath;

    /**
     * Should this file be copied to the target directory?
     */
    protected bool $doCopy = true;

    /**
     * Should this file be deleted from the source directory?
     */
    protected bool $doDelete = false;

    /** @var DiscoveredSymbol[] */
    protected array $discoveredSymbols = [];

    protected string $absoluteTargetPath;

    protected bool $didDelete = false;

    public function __construct(string $sourceAbsolutePath)
    {
        $this->sourceAbsolutePath = $sourceAbsolutePath;
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

    public function setDoPrefix(bool $doPrefix): void
    {
    }

    /**
     * Is this correct? Is there ever a time that NO changes should be made to a file? I.e. another file would have its
     * namespace changed and it needs to be updated throughout.
     *
     * Is this really a Symbol level function?
     */
    public function isDoPrefix(): bool
    {
        return true;
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
        return $this->doDelete;
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
        $this->discoveredSymbols[$symbol->getOriginalSymbol()] = $symbol;
    }

    /**
     * @return array<string, DiscoveredSymbol> The discovered symbols in the file, indexed by their original string name.
     */
    public function getDiscoveredSymbols(): array
    {
        return $this->discoveredSymbols;
    }

    public function setAbsoluteTargetPath(string $absoluteTargetPath): void
    {
        $this->absoluteTargetPath = $absoluteTargetPath;
    }

    /**
     * The target path to (maybe) copy the file to, and the target path to perform replacements in (which may be the
     * original path).
     */
    public function getAbsoluteTargetPath(): string
    {
        // TODO: Maybe this is a mistake and should better be an exception.
        return isset($this->absoluteTargetPath) ? $this->absoluteTargetPath : $this->sourceAbsolutePath;
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
}
