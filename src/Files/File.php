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

    public function __construct(string $sourceAbsolutePath)
    {
        $this->sourceAbsolutePath  = $sourceAbsolutePath;
    }

    public function getSourcePath(string $relativeTo = ''): string
    {
        return str_replace($relativeTo, '', $this->sourceAbsolutePath);
    }

    public function isPhpFile(): bool
    {
        return substr($this->sourceAbsolutePath, -4) === '.php';
    }

    public function addNamespace(string $namespaceName): void
    {
    }
    public function addClass(string $className): void
    {
    }
    public function addTrait(string $traitName): void
    {
    }
    // isTrait();

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
    public function isDoPrefix(): bool
    {
        return true;
    }

    /**
     * Used to mark files that are symlinked as not-to-be-deleted.
     *
     * @param bool $doDelete
     *
     * @return void
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

    public function setDidDelete(bool $didDelete): void
    {
    }
    public function getDidDelete(): bool
    {
        return false;
    }

    public function addDiscoveredSymbol(DiscoveredSymbol $symbol): void
    {
        $this->discoveredSymbols[$symbol->getOriginalSymbol()] = $symbol;
    }

    public function getContents(): string
    {

        // TODO: use flysystem
        // $contents = $this->filesystem->read($targetFile);

        $contents = file_get_contents($this->sourceAbsolutePath);
        if (false === $contents) {
            throw new \Exception("Failed to read file at {$this->sourceAbsolutePath}");
        }

        return $contents;
    }
}
