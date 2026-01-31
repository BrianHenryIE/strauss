<?php
/**
 * A namespace, class, interface or trait discovered in the project.
 */

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Pipeline\FileSymbolScanner;

abstract class DiscoveredSymbol
{
    /** @var array<FileBase> $sourceFiles */
    protected array $sourceFiles = [];

    protected ?string $namespace;

    protected string $fqdnOriginalSymbol;

    protected string $replacement;

    protected bool $doRename = true;

    protected ?ComposerPackage $package;

    /**
     * @param string $fqdnSymbol The classname / namespace etc.
     * @param FileBase $sourceFile The file it was discovered in.
     */
    public function __construct(
        string $fqdnSymbol,
        FileBase $sourceFile,
        string $namespace = '\\',
        ?ComposerPackage $package = null
    ) {
        $this->fqdnOriginalSymbol = $fqdnSymbol;

        $this->addSourceFile($sourceFile);
        $sourceFile->addDiscoveredSymbol($this);

        $this->namespace = $namespace;
        $this->package = $package;
    }

    public function getOriginalSymbol(): string
    {
        return $this->fqdnOriginalSymbol;
    }

    /**
     * @return FileBase[]
     */
    public function getSourceFiles(): array
    {
        return $this->sourceFiles;
    }

    /**
     * @see FileSymbolScanner
     */
    public function addSourceFile(FileBase $sourceFile): void
    {
        $this->sourceFiles[$sourceFile->getSourcePath()] = $sourceFile;
    }

    public function getReplacement(): string
    {
        return $this->isDoRename()
            ? ($this->replacement ?? $this->fqdnOriginalSymbol)
            : $this->fqdnOriginalSymbol;
    }

    public function setReplacement(string $replacement): void
    {
        $this->replacement = $replacement;
    }

    public function getNamespace(): ?string
    {
        return $this->namespace;
    }

    public function getOriginalLocalName(): string
    {
        return array_reverse(explode('\\', $this->fqdnOriginalSymbol))[0];
    }

    public function setDoRename(bool $doRename): void
    {
        $this->doRename = $doRename;
    }

    public function isDoRename(): bool
    {
        return $this->doRename;
    }

    public function getPackage(): ?ComposerPackage
    {
        return $this->package;
    }

    public function getPackageName(): ?string
    {
        if (!$this->package) {
            return null;
        }
        return $this->package->getPackageName();
    }
}
