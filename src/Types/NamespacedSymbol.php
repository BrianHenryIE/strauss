<?php

declare(strict_types=1);

/**
 * This is used so NamespaceSymbol doesn't have a namespace property itself.
 * Objects/classes inheriting from this could just be in the global namespace.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Files\FileWithDependency;

/**
 * Holds the {@see NamespaceSymbol} the symbol was declared in and derives the original and replacement fully
 * qualified names from it: in the global namespace the symbol's own local replacement (prefix) is used, otherwise
 * the namespace's replacement is joined to the original local name. A symbol is only renamed when its namespace
 * is global or is itself being renamed. {@see self::getPsr0NamespaceString()} matches each source file's
 * package-relative path against its package's `psr-0` paths so underscore-style classes can be renamed.
 */
class NamespacedSymbol extends DiscoveredSymbol
{
    protected NamespaceSymbol $namespace;
    protected bool $isAutoloaded = false;

    public function __construct(
        string $fqdnSymbol,
        FileBase $sourceFile,
        NamespaceSymbol $namespace,
        ?ComposerPackage $composerPackage = null
    ) {
        $this->namespace = $namespace;

        parent::__construct($fqdnSymbol, $sourceFile, $composerPackage);
    }

    public function getOriginalFqdnName(): string
    {
        return $this->namespace->isGlobal()
             ? $this->getOriginalLocalName()
             : $this->namespace->getOriginalFqdnName() . '\\' . $this->getOriginalLocalName();
    }

    /**
     * Defaults to the original until otherwise set.
     */
    public function getReplacementFqdnName(): string
    {
        if (!$this->isDoRename()) {
            return $this->fqdnOriginalSymbol;
        }
        return $this->getNamespace()->isGlobal()
            ? $this->getLocalReplacement()
            : trim($this->namespace->getLocalReplacement() . '\\' . $this->getOriginalLocalName(), '\\');
    }

    public function getNamespace(): NamespaceSymbol
    {
        return $this->namespace;
    }

    public function getNamespaceName(): string
    {
        return $this->namespace->getOriginalFqdnName();
    }

    public function isDoRename(): bool
    {
        return parent::isDoRename()
               && // If it has a non-global namespace, ensure that should be renamed.
               ($this->namespace->isGlobal() || $this->namespace->isDoRename());
    }

    public function isGlobal(): bool
    {
        return $this->namespace->isGlobal();
    }

    public function isPsr0Autoloaded(): bool
    {
        return (bool) $this->getPsr0NamespaceString();
    }

    public function getPsr0NamespaceString(): ?string
    {
        /**
 * @var ComposerPackage $dependency
*/
        foreach ($this->dependencies as $dependency) {
            if (! $dependency->isPsr0Autoloaded()) {
                continue;
            }
            foreach ($this->getSourceFiles() as $file) {
                if (! ( $file instanceof FileWithDependency )) {
                    continue;
                }
                if ($file->getDependency()->getPackageName() === $dependency->getPackageName()) {
                    /**
                     * This is verified in {@see ComposerPackage::isPsr0Autoloaded()}.
                     *
                     * @var            string $psr0namespace
                     * @var            string|string[] $autoloadPackageRelativePaths
                     * @phpstan-ignore offsetAccess.notFound
                     */
                    foreach ($dependency->getAutoload()['psr-0'] as $psr0namespace => $autoloadPackageRelativePaths) {
                        foreach ((array) $autoloadPackageRelativePaths as $autoloadPackageRelativePath) {
                            if (str_starts_with(
                                trim($file->getPackageRelativePath(), '\\/'),
                                trim($autoloadPackageRelativePath, '\\/')
                            )
                            ) {
                                return $psr0namespace;
                            }
                        }
                    }
                }
            }
        }
        return null;
    }

    public function setIsAutoloaded(bool $isAutoloaded): void
    {
        $this->isAutoloaded = $isAutoloaded;
    }

    public function isAutoloaded(): bool
    {
        return $this->isAutoloaded;
    }
}
