<?php
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

class NamespacedSymbol extends DiscoveredSymbol
{

    protected NamespaceSymbol $namespace;

    protected string $fqdnOriginalSymbol;

    public function __construct(
        string $fqdnSymbol,
        FileBase $sourceFile,
        ?NamespaceSymbol $namespace = null,
        ?ComposerPackage $composerPackage = null
    ) {
        parent::__construct($fqdnSymbol, $sourceFile, $composerPackage);

        $this->namespace = $namespace ?? NamespaceSymbol::global();
    }

    public function getOriginalFqdnName(): string
    {
        return $this->namespace->getOriginalSymbol() . '\\' . $this->getOriginalLocalName();
    }

    public function getFqdnReplacement(): string
    {
        return $this->isDoRename()
            ? trim($this->namespace->getLocalReplacement() . '\\' . $this->getLocalReplacement(), '\\')
            : $this->fqdnOriginalSymbol;
    }

    public function getNamespace(): NamespaceSymbol
    {
        return $this->namespace;
    }

    public function getNamespaceName(): string
    {
        return $this->namespace->getOriginalSymbol();
    }

    /**
     * Defaults to the original until otherwise set.
     */
    public function getReplacementFqdnName(): string
    {
        if (!$this->isDoRename()) {
            return $this->fqdnOriginalSymbol;
        }
        if (!$this->namespace->isGlobal()) {
            return $this->namespace->getReplacementFqdnName() . '\\' . ($this->localReplacement ?? $this->localOriginalSymbol);
        }
        return  $this->localReplacement ?? $this->localOriginalSymbol;
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
        /** @var ComposerPackage $dependency */
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
                     * @var string $psr0namespace
                     * @var string|string[] $autoloadPackageRelativePath
                     * @phpstan-ignore offsetAccess.notFound
                     */
                    foreach ($dependency->getAutoload()['psr-0'] as $psr0namespace => $autoloadPackageRelativePath) {
                        if (str_starts_with(
                            trim($file->getPackageRelativePath(), '\\/'),
                            trim($autoloadPackageRelativePath, '\\/')
                        )) {
                            return $psr0namespace;
                        }
                    }
                }
            }
        }
        return null;
    }
}
