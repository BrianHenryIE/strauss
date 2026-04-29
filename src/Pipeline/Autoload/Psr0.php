<?php
/**
 * After a PSR-0 namespaced class is renamed, its directory structure must be updated to match the required format.
 *
 * @see https://www.php-fig.org/psr/psr-0/
 * @see vendor/composer/composer/res/composer-schema.json
 * @see vendor/pimple/pimple/composer.json
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Pipeline\Autoload;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\DeepDependenciesCollection;
use BrianHenryIE\Strauss\Composer\DependenciesCollection;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Helpers\Filesystem;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

// We have a PSR-0 key
// PHP files may be in namespaces
// They may be global with underscores.

class Psr0
{
    use LoggerAwareTrait;

    protected Filesystem $filesystem;

    public function __construct(
        Filesystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
    }

    public function setTargetDirectory(
        DependenciesCollection $flatDependencyTree,
        DiscoveredFiles $discoveredFiles,
        DiscoveredSymbols $discoveredSymbols
    ): void {
        /** @var ComposerPackage $package */
        foreach ($flatDependencyTree as $package) {
            if (! $package->hasPsr0()) {
                continue;
            }

            $composerAutoloadKey = $package->getAutoload();

            /** @var FileWithDependency[] $allPackagesFiles */

            /** @var DiscoveredFiles $files */
            $allPackagesFiles = $package->getFiles();

            foreach ($composerAutoloadKey['psr-0'] as $psrRootNamespace => $packageRelativeNamespacePath) {
                // TODO: we need to have already run "determine changes" so we can set the target directory based on exclusion rules etc.
                $namespaceSymbol = $discoveredSymbols->getNamespace($psrRootNamespace);

                /** @var FileWithDependency $file */
                foreach ($allPackagesFiles as $file) {
                    $filePackageRelativePath = $file->getPackageRelativePath();

                    if (! str_starts_with(
                        $filePackageRelativePath,
                        $packageRelativeNamespacePath
                    )) {
                        continue;
                    }

                    // It doesn't matter here whether we are using actual namespaces or underscored classnames, the target directoru still changes.

                    $originalNamespaceString    = $this->filesystem->normalizePath(
                        $packageRelativeNamespacePath . '/' . $namespaceSymbol->getOriginalSymbol()
                    );
                    $replacementNamespaceString = $this->filesystem->normalizePath(
                        $packageRelativeNamespacePath . '/' . $namespaceSymbol->getReplacementFqdnName()
                    );

                    $updatedRelativePath = preg_replace(
                        '#^' . $originalNamespaceString . '#',
                        $replacementNamespaceString,
                        $filePackageRelativePath
                    );

                    $updatedTargetPath = preg_replace(
                        '#' . $filePackageRelativePath . '$#',
                        $updatedRelativePath,
                        $file->getTargetAbsolutePath()
                    );

                    $file->setTargetAbsolutePath($this->filesystem->normalizePath($updatedTargetPath));
                    $file->addAutoloader('psr-0');
                }
            }
        }
        // Has exclude-from-classmap become invalid?
    }
}
