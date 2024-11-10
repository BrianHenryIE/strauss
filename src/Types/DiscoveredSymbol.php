<?php
/**
 * A namespace, class, interface or trait discovered in the project.
 */

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Pipeline\FileSymbolScanner;

abstract class DiscoveredSymbol
{
    protected ?File $sourceFile;

    protected string $symbol;

    protected string $replacement;

    /**
     * @param string $symbol The classname / namespace etc.
     * @param File $sourceFile The file it was discovered in.
     */
    public function __construct(string $symbol, File $sourceFile)
    {
        $this->symbol     = $symbol;
        $this->sourceFile = $sourceFile;

        $sourceFile->addDiscoveredSymbol($this);
    }

    public function getOriginalSymbol(): string
    {
        return $this->symbol;
    }

    public function setSymbol(string $symbol): void
    {
        $this->symbol = $symbol;
    }

    public function getSourceFile(): ?File
    {
        return $this->sourceFile;
    }

    /**
     * @param File $sourceFile
     *
     * @see FileSymbolScanner
     */
    public function setSourceFile(File $sourceFile): void
    {
        $this->sourceFile = $sourceFile;
    }

    public function getReplacement(): string
    {
        return $this->replacement ?? $this->symbol;
    }

    public function setReplacement(string $replacement): void
    {
        $this->replacement = $replacement;
    }
}
