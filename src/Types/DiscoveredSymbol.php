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

    protected string $fqdnOriginalSymbol;

    protected string $replacement;

    /**
     * @param string $fqdnSymbol The classname / namespace etc.
     * @param File $sourceFile The file it was discovered in.
     */
    public function __construct(string $fqdnSymbol, File $sourceFile, string $namespace = '\\')
    {
        $this->fqdnOriginalSymbol = $fqdnSymbol;

        $this->addSourceFile($sourceFile);
        $sourceFile->addDiscoveredSymbol($this);

        $this->namespace = $namespace;
    }

    public function getOriginalSymbol(): string
    {
        return $this->fqdnOriginalSymbol;
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
        return $this->replacement ?? $this->fqdnOriginalSymbol;
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
}
