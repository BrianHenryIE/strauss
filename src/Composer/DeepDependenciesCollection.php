<?php
/**
 * Extension of DirectDependencies which represents all Composer Packages in the dependency tree.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Composer;

/**
 * Flattens the tree in its constructor: each given {@see ComposerPackage} and, recursively, every package returned by
 * {@see ComposerPackage::getDependencies()} is added once, keyed by package name, before being passed to the parent
 * {@see DependenciesCollection}. Already-seen package names are skipped, which also prevents infinite recursion on cycles.
 */
class DeepDependenciesCollection extends DependenciesCollection
{

    /**
     * @param array<ComposerPackage> $dependencies Top-level packages whose dependency trees are flattened into this collection.
     */
    public function __construct(
        array $dependencies
    ) {
        $flatDependencies = [];
        foreach ($dependencies as $dependency) {
            $flatDependencies[$dependency->getPackageName()] = $dependency;
            $flatDependencies = self::getDependenciesRecursive($dependency, $flatDependencies);
        }
        parent::__construct($flatDependencies);
    }

    /**
     * @param ComposerPackage   $composerPackage       Package whose dependencies are added, recursively.
     * @param ComposerPackage[] $flatDependenciesArray Packages collected so far, keyed by package name; already-present names are skipped.
     *
     * @return ComposerPackage[]
     */
    protected static function getDependenciesRecursive(ComposerPackage $composerPackage, array $flatDependenciesArray): array
    {
        foreach ($composerPackage->getDependencies() as $dependency) {
            if (isset($flatDependenciesArray[$dependency->getPackageName()])) {
                continue;
            }
            $flatDependenciesArray[$dependency->getPackageName()] = $dependency;
            $flatDependenciesArray = self::getDependenciesRecursive($dependency, $flatDependenciesArray);
        }
        return $flatDependenciesArray;
    }
}
