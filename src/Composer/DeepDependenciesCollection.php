<?php
/**
 * Extension of DirectDependencies which represents all Composer Packages in the dependency tree.
 */

namespace BrianHenryIE\Strauss\Composer;

class DeepDependenciesCollection extends DependenciesCollection
{

    /**
     * @param array<ComposerPackage> $dependencies
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
     * @param ComposerPackage $composerPackage
     * @param ComposerPackage[] $flatDependenciesArray
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
            foreach ($dependency->getDependencies() as $childDependency) {
                $flatDependenciesArray = array_merge($flatDependenciesArray, self::getDependenciesRecursive($childDependency, $flatDependenciesArray));
            }
        }
        return $flatDependenciesArray;
    }
}
