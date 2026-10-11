<?php
/**
 * A file without a dependency means the project src files and the vendor/composer autoload files.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Files;

use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;

/**
 * Holds the source absolute path, the vendor-relative path (which cannot always be inferred through a symlink) and
 * the target path, plus the copy/prefix/update/delete flags pipeline steps set on it. Collects the
 * {@see DiscoveredSymbols} defined in the file, its {@see FileCodeMap} and the edits planned against that map;
 * replacing the map discards the planned edits.
 */
class File implements FileBase
{
    /**
     * @var string The absolute path to the file on disk.
     */
    protected string $sourceAbsolutePath;

    /**
     * This cannot always be inferred from sourceAbsolutePath when that is via a symlink.
     */
    protected string $vendorRelativePath;

    protected string $targetAbsolutePath;


    protected DiscoveredSymbols $discoveredSymbols;

    /**
     * Should this file be copied to the target directory?
     */
    protected bool $doCopy = true;

    /**
     * Do prefix/renane symbols found in this file.
     */
    protected bool $doPrefix = true;

    /**
     * Do edit this file.
     */
    protected bool $doUpdate = true;

    /**
     * Should this file be deleted from the source directory?
     *
     * `null` means defer to the package's `isDelete` setting.
     */
    protected ?bool $doDelete = false;

    protected bool $didDelete = false;

    /**
     * What the file defines and every symbol it uses, from parsing it once in
     * {@see \BrianHenryIE\Strauss\Pipeline\FileSymbolScanner}. Null for non-PHP files, files that could not be
     * parsed, and files not yet scanned.
     */
    protected ?FileCodeMap $codeMap = null;

    /**
     * The pre-calculated changes to make to the file: position, end and replacement string, planned against
     * {@see self::$codeMap}. Null distinguishes "not planned yet" from "planned, and there is nothing to change".
     *
     * @var ?array<array{start:int,end:int,replacement:string}>
     */
    protected ?array $plannedEdits = null;

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
     * @param bool $doCopy True to copy the file to the target directory; false to leave it in place.
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

    public function setIsAutoloaded(bool $isAutoloaded): void
    {
        // TODO: We really need to configure PhpStan so it knows a list of files returned from a package are `FileWithDependency`.
    }

    /**
     * Should symbols discovered in this file be prefixed. (i.e. class definitions etc., not usages)
     *
     * @param bool $doPrefix True if symbols defined in this file should be prefixed.
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
     * @param bool $doDelete True to delete the source file after copying; false to keep it.
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
     * @param bool $didDelete Whether the file has been deleted from the vendor directory.
     *
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

    /**
     * Replacing the code map discards any planned edits: a plan is only valid for the map it was made against.
     *
     * @param ?FileCodeMap $codeMap The parsed code map for the file's current contents; null to clear it.
     */
    public function setCodeMap(?FileCodeMap $codeMap): void
    {
        $this->codeMap = $codeMap;
        $this->plannedEdits = null;
    }

    /**
     * The symbols this file defines and uses, and the regions of text (comments, strings) that may name symbols.
     * Null when the file has not been, or could not be, parsed.
     */
    public function getCodeMap(): ?FileCodeMap
    {
        return $this->codeMap;
    }

    /**
     * @param ?array<array{start:int,end:int,replacement:string}> $plannedEdits Null to mark the file as unplanned.
     */
    public function setPlannedEdits(?array $plannedEdits): void
    {
        $this->plannedEdits = is_null($plannedEdits) ? null : array_values($plannedEdits);
    }

    /**
     * @return ?array<array{start:int,end:int,replacement:string}> Null when no changes have been planned yet.
     */
    public function getPlannedEdits(): ?array
    {
        return $this->plannedEdits;
    }
}
