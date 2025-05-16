<?php
/**
 * A namespace, class, interface or trait discovered in the project.
 */

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Pipeline\FileSymbolScanner;

abstract class DiscoveredSymbol
{
    /** @var array<File> $sourceFiles */
    protected array $sourceFiles = [];

    protected ?string $namespace;

    protected string $symbol;

    protected string $replacement;

    /**
     * @param string $symbol The classname / namespace etc.
     * @param File $sourceFile The file it was discovered in.
     */
    public function __construct(string $symbol, File $sourceFile, ?string $namespace = null)
    {
        $this->symbol = $symbol;

        $this->addSourceFile($sourceFile);
        $sourceFile->addDiscoveredSymbol($this);

        if ($namespace !=='global') {
            $this->namespace = $namespace;
        }
    }

    public function getOriginalSymbol(): string
    {
        return $this->symbol;
    }

    /**
     * @return File[]
     */
    public function getSourceFiles(): array
    {
        return $this->sourceFiles;
    }

    /**
     * @param File $sourceFile
     *
     * @see FileSymbolScanner
     */
    public function addSourceFile(File $sourceFile): void
    {
        $this->sourceFiles[$sourceFile->getSourcePath()] = $sourceFile;
    }

    public function getReplacement(): string
    {
        return $this->replacement ?? $this->symbol;
    }

    public function setReplacement(string $replacement): void
    {
        $this->replacement = $replacement;
    }

    public function getNamespace(): ?string
    {
        return $this->namespace;
    }
}
