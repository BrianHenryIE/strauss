<?php

declare(strict_types=1);

/**
 * A namespace, class, interface or trait discovered in the project.
 */

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\DependenciesCollection;
use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Pipeline\ChangeEnumerator;
use BrianHenryIE\Strauss\Pipeline\FileSymbolScanner;
use Composer\Package\PackageInterface;

abstract class DiscoveredSymbol
{
    /**
     * The file(s) where this symbol was defined.
     *
     * @var array<FileBase> $sourceFiles
     */
    protected array $sourceFiles = [];

    // E.g. for `My\Ns\Classname` this is just `Classname`.
    protected string $localOriginalSymbol;

    protected string $fqdnOriginalSymbol;

    protected ?string $localReplacement = null;

    protected bool $doRename = true;

    // Possibly empty.
    protected DependenciesCollection $dependencies;

    /**
     * @param string $fqdnSymbol The classname / namespace etc.
     * @param ?FileBase $sourceFile The file it was discovered in. Unneeded for global namespace and some (Composer) predictable files.
     */
    public function __construct(
        string $fqdnSymbol,
        ?FileBase $sourceFile = null,
        ?ComposerPackage $composerPackage = null
    ) {
        $this->dependencies = new DependenciesCollection([]);
        if ($composerPackage) {
            $this->dependencies->add($composerPackage);
        }

        $this->fqdnOriginalSymbol = $fqdnSymbol;

        if (!str_contains($fqdnSymbol, '\\') || ($this instanceof NamespaceSymbol)) {
            $this->localOriginalSymbol = $fqdnSymbol;
        } else {
            $this->localOriginalSymbol = array_reverse(explode('\\', $fqdnSymbol))[0];
        }

        if ($sourceFile) {
            $this->addSourceFile($sourceFile);
            $sourceFile->addDiscoveredSymbol($this);
        }
    }

    abstract public function isGlobal(): bool;

    /**
     * TODO: Document does this contain or ltrim the leading slash.
     */
    public function getOriginalFqdnName(): string
    {
        return $this->fqdnOriginalSymbol;
    }

    /**
     * Defaults to the original until otherwise set.
     */
    public function getReplacementFqdnName(): string
    {
        // TODO: Should this be here or should `::isDoRename()` always be called at the calling site.
        return $this->isDoRename()
            ? trim(($this->localReplacement ?? $this->fqdnOriginalSymbol), '\\')
            : $this->fqdnOriginalSymbol;
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
        $this->sourceFiles[$sourceFile->getVendorRelativePath()] = $sourceFile;

        if ($sourceFile instanceof FileWithDependency) {
            $this->addDependency($sourceFile->getDependency());
        }
    }

    public function getLocalReplacement(): string
    {
        return $this->isDoRename()
            ? ($this->localReplacement ?? $this->localOriginalSymbol)
            : $this->localOriginalSymbol;
    }

    public function setLocalReplacement(string $localReplacement): void
    {
        $this->localReplacement = $localReplacement;
    }

    public function getOriginalLocalName(): string
    {
        $fqdnParts = explode('\\', $this->fqdnOriginalSymbol);
        $localSymbol = array_pop($fqdnParts);
        return $localSymbol;
    }

    public function setDoRename(bool $doRename): void
    {
        $this->doRename = $doRename;
    }

    public function isDoRename(): bool
    {
        return $this->doRename;
    }

    /**
     * @return ComposerPackage[]
     */
    public function getPackages(): array
    {
        return array_values(array_unique(array_filter(array_map(
            function (FileBase $file) {
                return $file instanceof FileWithDependency
                    ? $file->getDependency()
                    : null;
            },
            $this->getSourceFiles()
        ))));
    }

    public function getPackageName(): ?string
    {
        $packages = $this->getPackages();
        if (0 === count($packages)) {
            return null;
        }
        // TODO: `if count(packages)>1`, warning.
        return $packages[0]->getPackageName();
    }

    /**
     * @deprecated This is only being called in {@see Prefixer::replaceSingleClassnameInString()}, the actual determination should be made in {@see ChangeEnumerator}.
     */
    public function getOriginalSymbolStripPrefix(string $classPrefix): string
    {
        $symbolName = $this->fqdnOriginalSymbol;

        while (str_starts_with($symbolName, $classPrefix) && trim($classPrefix, '_') !== trim($symbolName, '_')) {
            $symbolName = preg_replace('/^'.preg_quote($classPrefix) . '/', '', $symbolName) ?? $symbolName;
        }

        return trim($symbolName, '_');
    }

    public function __toString(): string
    {
        return $this->getOriginalFqdnName();
    }

    public function addDependency(\BrianHenryIE\Strauss\Composer\ComposerPackage $package): void
    {
        $this->dependencies->add($package);
    }

    public function getDependencies(): DependenciesCollection
    {
        return $this->dependencies;
    }
}
