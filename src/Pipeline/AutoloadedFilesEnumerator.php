<?php
/**
 * Use each package's autoload key to determine which files in the package are to be prefixed, apply exclusion rules.
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\AutoloadFilesEnumeratorConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use Composer\ClassMapGenerator\ClassMapGenerator;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class AutoloadedFilesEnumerator
{
    use LoggerAwareTrait;

    protected AutoloadFilesEnumeratorConfigInterface $config;
    protected FileSystem $filesystem;

    public function __construct(
        AutoloadFilesEnumeratorConfigInterface $config,
        FileSystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->setLogger($logger);
    }

    /**
     * @param ComposerPackage[] $dependencies
     */
    public function markFilesForInclusion(array $dependencies): void
    {
        foreach ($dependencies as $dependency) {
            $this->scanPackage($dependency);
        }
    }

    /**
     * Read the autoload keys of the dependencies and marks the appropriate files to be prefixed
     */
    protected function scanPackage(ComposerPackage $dependency): void
    {
        $this->logger->info("Scanning for autoloaded files in package {packageName}", ['packageName' => $dependency->getPackageName()]);

        if ($this->isPackageExcluded($dependency->getPackageName())) {
            return;
        }

        $dependencyAutoloadKey = $dependency->getAutoload();
        $excludeFromClassmap = isset($dependencyAutoloadKey['exclude_from_classmap']) ? $dependencyAutoloadKey['exclude_from_classmap'] : [];

        /**
         * Where $dependency->autoload is ~
         *
         * [ "psr-4" => [ "BrianHenryIE\Strauss" => "src" ] ]
         * Exclude "exclude-from-classmap"
         * @see https://getcomposer.org/doc/04-schema.md#exclude-files-from-classmaps
         */
        $autoloaders = array_filter($dependencyAutoloadKey, function ($type) {
            return 'exclude-from-classmap' !== $type;
        }, ARRAY_FILTER_USE_KEY);

        $dependencyPackageAbsolutePath = $dependency->getPackageAbsolutePath();

        $classMapGenerator = new ClassMapGenerator();


        $excluded = null;
        $autoloadType = 'classmap';
        $namespace = null;
        $excludedDirs = array_map(
            fn(string $path) => $dependencyPackageAbsolutePath . '/' . $path,
            $excludeFromClassmap
        );


        foreach ($autoloaders as $type => $value) {
            // Might have to switch/case here.

            switch ($type) {
                case 'files':
                    $filesAbsolutePaths = array_map(
                        fn(string $path) => $dependencyPackageAbsolutePath . '/' . $path,
                        (array)$value
                    );
                    $filesAutoloaderFiles = $this->filesystem->findAllFilesAbsolutePaths($filesAbsolutePaths, true);
                    foreach ($filesAutoloaderFiles as $filePackageAbsolutePath) {
                        if ($this->isFileExcluded($filePackageAbsolutePath)) {
                            continue;
                        }

                        $filePackageRelativePath = $this->filesystem->getRelativePath(
                            $dependencyPackageAbsolutePath,
                            $filePackageAbsolutePath
                        );
                        $file = $dependency->getFile($filePackageRelativePath);
                        if (!$file) {
                            $this->logger->warning("Expected discovered file at {relativePath} not found in package {packageName}", [
                                'relativePath' => $filePackageRelativePath,
                                'packageName' => $dependency->getPackageName(),
                            ]);
                        } else {
                            $file->setDoPrefix(true);
                        }
                    }
                    break;
                case 'classmap':
                    $autoloadKeyPaths = array_map(
                        fn(string $path) =>
                            '/'. $this->filesystem->normalize(
                                $dependencyPackageAbsolutePath . $path
                            ),
                        (array)$value
                    );
                    foreach ($autoloadKeyPaths as $autoloadKeyPath) {
                        $classMapGenerator->scanPaths(
                            $autoloadKeyPath,
                            $excluded,
                            $autoloadType,
                            $namespace,
                            $excludedDirs,
                        );
                    }

                    break;
                case 'psr-0':
                case 'psr-4':
                    foreach ((array)$value as $namespace => $namespaceRelativePaths) {
                        if ($this->isNamespaceExcluded($namespace)) {
                            continue;
                        }

                        $psrPaths = array_map(
                            fn(string $path) => $dependencyPackageAbsolutePath . '/' . $path,
                            (array)$namespaceRelativePaths
                        );

                        foreach ($psrPaths as $autoloadKeyPath) {
                            $classMapGenerator->scanPaths(
                                $autoloadKeyPath,
                                $excluded,
                                $autoloadType,
                                $namespace,
                                $excludedDirs,
                            );
                        }
                    }
                    break;
                default:
                    $this->logger->info('Unexpected autoloader type');
                    // TODO: include everything;
                    break;
            }
        }

        $classMap = $classMapGenerator->getClassMap();
        $classMapPaths = $classMap->getMap();
        foreach ($classMapPaths as $fileAbsolutePath) {
            if ($this->isFileExcluded($fileAbsolutePath)) {
                continue;
            }

            $relativePath = $this->filesystem->getRelativePath($dependency->getPackageAbsolutePath(), $fileAbsolutePath);
            $file = $dependency->getFile($relativePath);
            if (!$file) {
                $this->logger->warning("Expected discovered file at {relativePath} not found in package {packageName}", [
                    'relativePath' => $relativePath,
                    'packageName' => $dependency->getPackageName(),
                ]);
            } else {
                $file->setDoPrefix(true);
            }
        }
    }

    public function markFilesForExclusion(DiscoveredFiles $files): void
    {
        foreach ($files->getFiles() as $file) {
            if ($file instanceof FileWithDependency) {
                if (in_array(
                    $file->getDependency()->getPackageName(),
                    $this->config->getExcludePackagesFromPrefixing(),
                    true
                )) {
                    $file->setDoPrefix(false);
                    continue;
                }

                foreach ($this->config->getExcludeFilePatternsFromPrefixing() as $excludeFilePattern) {
                    // TODO: This source relative path should be from the vendor dir.
                    // TODO: Should the target path be used here?
                    if (1 === preg_match($excludeFilePattern, $file->getVendorRelativePath())) {
                        $file->setDoPrefix(false);
                        foreach ($file->getDiscoveredSymbols() as $discoveredSymbol) {
                            $discoveredSymbol->setDoRename(false);
                        }
                    }
                }
            }
        }
    }

    protected function isPackageExcluded(string $packageName): bool
    {
        if (in_array(
            $packageName,
            $this->config->getExcludePackagesFromPrefixing(),
            true
        )) {
            return true;
        }
        if (in_array(
            $packageName,
            $this->config->getExcludePackagesFromCopy(),
            true
        )) {
            return true;
        }
        return false;
    }

    protected function isNamespaceExcluded(string $namespace): bool
    {
        if (!empty($namespace) && in_array($namespace, $this->config->getExcludeNamespacesFromPrefixing())) {
            $this->logger->info("Excluding namespace " . $namespace);
            return true;
        }
        if (!empty($namespace) && in_array($namespace, $this->config->getExcludeNamespacesFromCopy())) {
            $this->logger->info("Excluding namespace " . $namespace);
            return true;
        }
        return false;
    }

    /**
     * Compares the relative path from the vendor dir with `exclude_file_patterns` config.
     *
     * @param string $absoluteFilePath
     * @return bool
     */
    protected function isFileExcluded(string $absoluteFilePath): bool
    {
        foreach ($this->config->getExcludeFilePatternsFromPrefixing() as $excludeFilePattern) {
            $vendorRelativePath = $this->filesystem->getRelativePath($this->config->getVendorDirectory(), $absoluteFilePath);
            if (1 === preg_match($this->preparePattern($excludeFilePattern), $vendorRelativePath)) {
                return true;
            }
        }
        foreach ($this->config->getExcludeFilePatternsFromCopy() as $excludeFilePattern) {
            $vendorRelativePath = $this->filesystem->getRelativePath($this->config->getVendorDirectory(), $absoluteFilePath);
            if (1 === preg_match($this->preparePattern($excludeFilePattern), $vendorRelativePath)) {
                return true;
            }
        }
        return false;
    }

    private function preparePattern(string $pattern): string
    {
        $delimiter = '#';

        if (substr($pattern, 0, 1) !== substr($pattern, - 1, 1)) {
            $pattern = $delimiter . $pattern . $delimiter;
        }

        return $pattern;
    }
}
