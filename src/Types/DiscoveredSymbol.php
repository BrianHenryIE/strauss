<?php
/**
 * A namespace, class, interface or trait discovered in the project.
 */

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Files\FileWithDependency;
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

    /**
     * @param string $fqdnSymbol The classname / namespace etc.
     * @param ?FileBase $sourceFile The file it was discovered in. Unneeded for global namespace and some (Composer) predictable files.
     */
    public function __construct(
        string $fqdnSymbol,
        ?FileBase $sourceFile = null
    ) {
        $this->fqdnOriginalSymbol = $fqdnSymbol;

        // TODO: Add `::isGlobal()` to `NamespacedSymbol`.
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

    public function getOriginalSymbol(): string
    {
        return $this->fqdnOriginalSymbol;
    }

    /**
     * Defaults to the original until otherwise set.
     */
    public function getReplacementFqdnName(): string
    {
        // TODO: Should this be here ot should `::isDoRename()` always be called at the calling site.
        return $this->isDoRename()
            ? ($this->localReplacement ?? $this->fqdnOriginalSymbol)
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
        $this->sourceFiles[$sourceFile->getSourcePath()] = $sourceFile;
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

    public function getOriginalSymbolStripPrefix(string $class_prefix): string
    {
        $fqdnOriginalSymbol = $this->fqdnOriginalSymbol;

        while (str_starts_with($fqdnOriginalSymbol, $class_prefix) && $class_prefix !== $fqdnOriginalSymbol) {
            $fqdnOriginalSymbol = preg_replace('/^'.preg_quote($class_prefix).'/', '', $fqdnOriginalSymbol);
            if (is_null($fqdnOriginalSymbol)) {
                return $this->fqdnOriginalSymbol;
            }
        }

        return $fqdnOriginalSymbol;
    }

    public function __toString(): string
    {
        return $this->getOriginalSymbol();
    }
}
