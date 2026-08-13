<?php
/**
 * Use each package's autoload key to determine which files in the package are to be prefixed, apply exclusion rules.
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\DependenciesCollection;
use BrianHenryIE\Strauss\Config\AutoloadFilesEnumeratorConfigInterface;
use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespacedSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Composer\ClassMapGenerator\ClassMapGenerator;
use Exception;
use League\Flysystem\FilesystemException;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use SplFileInfo;

/**
 * @phpstan-import-type AutoloadKeyArray from ComposerPackage
 */
class AutoloadedEnumerator
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
     * If a namespace is in a `psr-0` or `psr-4` key, mark the symbol as autoloaded.
     * If a file contains a namespace that is autoloaded, mark the file as autoloaded.
     * If a file is in a `files` or `classmap` key, mark the file as autoloaded
     *
     * If a file is in a `files` or `classmap` key, mark all its symbols as autoloaded.
     *
     * @param DiscoveredFiles $discoveredFiles
     * @param DiscoveredSymbols $discoveredSymbols
     */
    public function scanSetIsAutoloaded(DiscoveredFiles $discoveredFiles, DiscoveredSymbols $discoveredSymbols): void
    {
        /** @var NamespaceSymbol $namespaceSymbol */
        foreach ($discoveredSymbols->getNamespaces() as $namespaceSymbol) {
            /**
             * A namespace may have already been marked as autoloaded when read from the autoload key itself.
             * I.e. some root namespaces `BrianHenryIE\\` are valid namespace but contain no symbols.
             *
             * @see DependenciesCommand::enumeratePsrNamespaces()
             */
            if ($this->isNamespaceInPsr4Autoloader($namespaceSymbol)) {
                if (!$namespaceSymbol->isAutoloaded()) {
                    $this->logger->info($namespaceSymbol->getPackageName() . ' ' . $namespaceSymbol->getOriginalLocalName() . ' namespace marked autoloaded by psr-4');
                    $namespaceSymbol->setIsAutoloaded(true);
                }
                /** @var FileWithDependency $file */
                foreach ($namespaceSymbol->getSourceFiles() as $file) {
                    if (!$file->isAutoloaded()) {
                        $this->logger->info($file->getVendorRelativePath() . ' marked autoloaded because it contains autoloaded psr-4 namespace ' . $namespaceSymbol->getOriginalLocalName());
                        $file->setIsAutoloaded(true);
                    }
                }
            }
            if ($this->isNamespaceInPsr0Autoloader($namespaceSymbol)) {
                if (!$namespaceSymbol->isAutoloaded()) {
                    $this->logger->info($namespaceSymbol->getOriginalLocalName() . ' namespace marked autoloaded by psr-0');
                    $namespaceSymbol->setIsAutoloaded(true);
                }

                /** @var FileWithDependency $file */
                foreach ($namespaceSymbol->getSourceFiles() as $file) {
                    if (!$file->isAutoloaded()) {
                        $this->logger->info($file->getVendorRelativePath() . ' marked autoloaded because it contains autoloaded psr-0 namespace ' . $namespaceSymbol->getOriginalLocalName());
                        $file->setIsAutoloaded(true);
                    }
                }
            }
            unset($namespaceSymbol, $file);
        }

        foreach ($discoveredFiles as $file) {
            if (! $file->isPhpFile()) {
                continue;
            }

            if (! ( $file instanceof FileWithDependency )) {
                continue;
            }

            if (! $file->isAutoloaded() && $this->isFileInClassmapAutoloader($file)) {
                $this->logger->info($file->getVendorRelativePath() . ' autoloaded by classmap autoloader');
                $file->setIsAutoloaded(true);
            }

            if (! $file->isAutoloaded() && $this->isFileInFilesAutoloader($file)) {
                $this->logger->info($file->getVendorRelativePath() . ' autoloaded by files autoloader');
                $file->setIsAutoloaded(true);
            }

            /** @var NamespacedSymbol $namespacedSymbol */
            if (! $file->isAutoloaded()) {
                foreach ($file->getDiscoveredSymbols()->getNamespacedSymbols() as $namespacedSymbol) {
                    if ($namespacedSymbol->isAutoloaded()) {
                        $this->logger->info($file->getVendorRelativePath() . ' marked autoloaded because it contains autoloaded symbol ' . $namespacedSymbol->getOriginalLocalName());
                        $file->setIsAutoloaded(true);
                    }
                    unset($namespacedSymbol);
                }
            }

            // E.g. wp-graphql's global `WPGraphQL` class in its psr-4 `src/` directory: not itself resolvable
            // by the psr-4 rules, but part of the package's autoloaded code, and its call sites will be
            // updated, so the symbols it declares must be renamed to match.
            if (!$file->isAutoloaded() && $this->isFileInPsr4Autoloader($file)) {
                $this->logger->info($file->getVendorRelativePath() . ' marked autoloaded because it is in a psr-4 autoloaded directory');
                $file->setIsAutoloaded(true);
            }

            if (!$file->isAutoloaded() && $this->isFileInPsr0Autoloader($file)) {
                $this->logger->info($file->getVendorRelativePath() . ' marked autoloaded because it is in a psr-0 autoloaded directory');
                $file->setIsAutoloaded(true);
                foreach ($file->getDiscoveredSymbols() as $symbol) {
                    if (!$symbol->isAutoloaded()) {
                        switch (true) {
                            case $symbol instanceof NamespacedSymbol && $symbol->getNamespaceName() === '\\':
                                break;
                            case $symbol instanceof NamespacedSymbol:
                            case $symbol instanceof NamespaceSymbol:
                                $this->logger->info($symbol->getOriginalLocalName() . ' marked autoloaded because it is autoloaded file ' . $file->getPackageRelativePath());
                                $symbol->setIsAutoloaded(true);
                                break;
                            default:
                                throw new Exception('unimplemented');
                        }
                    }
                }
            }

            /** @var DiscoveredSymbol $symbol */
            foreach ($file->getDiscoveredSymbols() as $symbol) {
                if (! $symbol->isAutoloaded() && $file->isAutoloaded()) {
                    $this->logger->info($symbol->getOriginalLocalName() . ' marked autoloaded because it is in autoloaded file ' . $file->getPackageRelativePath());
                    $symbol->setIsAutoloaded(true);
                }
            }
            unset($file);
        }

        // This should already be correct.
        do {
            $foundCount = 0;

            // Mark all files a symbols is defined in as autoloaded (maybe this is the wrong terminology).
            foreach ($discoveredSymbols->getNamespacedSymbols() as $symbol) {
                // Already marked.
                if ($symbol->isAutoloaded()) {
                    continue;
                }

                /** @var FileWithDependency $file */
                foreach ($symbol->getSourceFiles() as $file) {
                    if (!$file->isAutoloaded()) {
                        // Not relevant.
                        continue;
                    }
                    $this->logger->info($symbol->getOriginalLocalName() . ' marked autoloaded because it is in autoloaded file ' . $file->getPackageRelativePath());
                    $symbol->setIsAutoloaded(true);
                    $foundCount++;
                }
            }

            // If any symbol in a file is autoloaded, mark them all as autoloaded.
            foreach ($discoveredFiles as $file) {
                // Already marked
                if ($file->isAutoloaded()) {
                    continue;
                }
                foreach ($file->getDiscoveredSymbols() as $symbol) {
                    if (!$symbol->isAutoloaded()) {
                        // Not relevant.
                        continue;
                    }
                    $this->logger->info($file->getVendorRelativePath() . ' marked autoloaded because contains autoloaded symbol ' . $symbol->getOriginalLocalName());
                    $file->setIsAutoloaded(true);
                    $foundCount++;
                }
            }
        } while ($foundCount !== 0);
    }

    protected function isNamespaceInPsr0Autoloader(NamespaceSymbol $namespaceSymbol): bool
    {
        foreach ($namespaceSymbol->getDependencies() as $package) {
            /** @var AutoloadKeyArray $packageAutoload */
            $packageAutoload = $package->getAutoload();

            foreach ($packageAutoload['psr-0'] ?? [] as $namespaceString => $directories) {
                if (str_starts_with($namespaceSymbol->getOriginalFqdnName(), trim($namespaceString, '\\'))) {
                    return true;
                }
            }
        }

        return false;
    }
    protected function isNamespaceInPsr4Autoloader(NamespaceSymbol $namespaceSymbol): bool
    {
        foreach ($namespaceSymbol->getDependencies() as $package) {
            /** @var AutoloadKeyArray $packageAutoload */
            $packageAutoload = $package->getAutoload();

            /**
             * @var string $namespaceString
             * @var string|string[] $directories
             */
            foreach ($packageAutoload['psr-4'] ?? [] as $namespaceString => $directories) {
                if (str_starts_with($namespaceSymbol->getOriginalFqdnName(), trim($namespaceString, '\\'))) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function isFileInFilesAutoloader(FileWithDependency $file): bool
    {
        $package = $file->getDependency();
        /** @var AutoloadKeyArray $packageAutoload */
        $packageAutoload = $package->getAutoload();

        // if a file is in a `files` list
        if (isset($packageAutoload['files'])) {
            $filesRelativePaths = $packageAutoload['files'];

            foreach ($filesRelativePaths as $path) {
                if ($file->getPackageRelativePath() === $path) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function isFileInClassmapAutoloader(FileWithDependency $file): bool
    {
        $package = $file->getDependency();
        /** @var AutoloadKeyArray $packageAutoload */
        $packageAutoload = $package->getAutoload();

        // if a file is in a `classmap` list
        if (isset($packageAutoload['classmap'])) {
            $directoryRelativePaths = $packageAutoload['classmap'];

            $excludeClassmapPaths = $packageAutoload['exclude_from_classmap'] ?? [];

            foreach ($directoryRelativePaths as $path) {
                foreach ($excludeClassmapPaths as $excludePath) {
                    if (str_starts_with($file->getPackageRelativePath(), $excludePath)) {
                        continue 2;
                    }
                }

                // All classes etc. in the package are autoloaded.
                if ('.' === $path) {
                    return true;
                }


                // TODO: Does this need `trim()`?
                if (str_starts_with($file->getPackageRelativePath(), $path)) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function isSymbolInFilesAutoloader(NamespacedSymbol $symbol): bool
    {
        foreach ($symbol->getDependencies() as $package) {
            /** @var AutoloadKeyArray $packageAutoload */
            $packageAutoload = $package->getAutoload();

            // if a file is in a `files` list
            if (isset($packageAutoload['files'])) {
                $filesPaths = $packageAutoload['files'];
                /**
                 * A NamespacedSymbol's files will all have a dependency.
                 *
                 * @var FileWithDependency $symbolFile
                 */
                foreach ($symbol->getSourceFiles() as $symbolFile) {
                    foreach ($filesPaths as $path) {
                        if (str_starts_with($symbolFile->getPackageRelativePath(), $path)) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    protected function isSymbolInClassmapAutoloader(NamespacedSymbol $symbol): bool
    {
        foreach ($symbol->getDependencies() as $package) {
            /** @var AutoloadKeyArray $packageAutoload */
            $packageAutoload = $package->getAutoload();

            // Does the package have a `autoload`.`classmap` array at all?
            if (!isset($packageAutoload['classmap'])) {
                continue;
            }

            // If a file is in a `classmap` directory.
            // TODO: are these entries strictly directories?
            $classmapPaths = $packageAutoload['classmap'];
            $excludeClassmapPaths = $packageAutoload['exclude_from_classmap'] ?? [];
            /** @var FileWithDependency $symbolFile */
            foreach ($symbol->getSourceFiles() as $symbolFile) {
                foreach ($classmapPaths as $path) {
                    // TODO: Does this need `trim()`?
                    foreach ($excludeClassmapPaths as $excludePath) {
                        if (str_starts_with($symbolFile->getPackageRelativePath(), $excludePath)) {
                            continue 2;
                        }
                    }

                    // All classes etc. in the package are autoloaded.
                    if ('.' === $path) {
                        return true;
                    }

                    // TODO: Does this need `trim()`?
                    if (str_starts_with($symbolFile->getPackageRelativePath(), $path)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    protected function isFileInPsr4Autoloader(FileWithDependency $file): bool
    {
        $package = $file->getDependency();
        foreach ($package->getAutoload()['psr-4'] ?? [] as $namespaceString => $directories) {
            foreach ((array) $directories as $directory) {
                if (str_starts_with(
                    ltrim($file->getPackageRelativePath(), '\\/'),
                    ltrim($directory, '\\/')
                )) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function isFileInPsr0Autoloader(FileWithDependency $file): bool
    {
        $package = $file->getDependency();
        foreach ($package->getAutoload()['psr-0'] ?? [] as $namespaceString => $directories) {
            foreach ((array) $directories as $directory) {
                if (str_starts_with(
                    ltrim($file->getPackageRelativePath(), '\\'),
                    ltrim($directory, '\\')
                )) {
                    return true;
                }
            }
        }
        return false;
    }


    //    public function scanForAutoloadedFiles(DependenciesCollection $dependencies): void
//    {
//        foreach ($dependencies as $dependency) {
//            $this->scanPackage($dependency);
//        }
//    }

    /**
     * Read the autoload keys of the dependencies and marks the appropriate files to be prefixed
     * @throws FilesystemException
     */
    protected function scanPackage(ComposerPackage $dependency): void
    {
        $this->logger->debug('AutoloadFileEnumerator::scanPackage() {packageName}', [ 'packageName' => $dependency->getPackageName() ]);

        // Meta packages.
        if (is_null($dependency->getPackageAbsolutePath())) {
            return;
        }

        $this->logger->info("Scanning for autoloaded files in package {packageName}", [ 'packageName' => $dependency->getPackageName() ]);

        $dependencyAutoloadKey = $dependency->getAutoload();
        $excludeFromClassmap   = isset($dependencyAutoloadKey['exclude_from_classmap']) ? $dependencyAutoloadKey['exclude_from_classmap'] : [];

        /**
         * Where $dependency->autoload is ~
         *
         * [ "psr-4" => [ "BrianHenryIE\Strauss" => "src" ] ]
         * Exclude "exclude-from-classmap"
         * @see https://getcomposer.org/doc/04-schema.md#exclude-files-from-classmaps
         */
        $autoloaders = array_filter($dependencyAutoloadKey, function ($type) {
            return 'exclude_from_classmap' !== $type;
        }, ARRAY_FILTER_USE_KEY);

        $dependencyPackageAbsolutePath   = $this->filesystem->makeAbsolute($dependency->getPackageAbsolutePath());
        $fsDependencyPackageAbsolutePath = $this->filesystem->makeAbsolute($dependencyPackageAbsolutePath);

        $excluded     = null;
        $autoloadType = 'classmap';

        // Used in Composer `ClassMapGenerator::scanPaths()`.
        $excludedDirs = array_map(
            fn(string $path) => $fsDependencyPackageAbsolutePath . '/' . $path,
            $excludeFromClassmap
        );

        foreach ($autoloaders as $autoloaderType => $value) {
            // Might have to switch/case here.

            $classMapGenerator = new ClassMapGenerator();

            /** @var ?string $namespace */
            $namespace = null;

            switch ($autoloaderType) {
                case 'files':
                    $filesAbsolutePaths   = array_map(
                        fn(string $path) => $dependencyPackageAbsolutePath . '/' . $path,
                        (array) $value
                    );
                    $filesAutoloaderFiles = $this->filesystem->findAllFilesAbsolutePaths($filesAbsolutePaths, true);
                    foreach ($filesAutoloaderFiles as $filePackageAbsolutePath) {
                        $filePackageRelativePath = $this->filesystem->getRelativePath(
                            $dependencyPackageAbsolutePath,
                            $filePackageAbsolutePath
                        );
                        $file                    = $dependency->getFile(FileSystem::normalizeDirSeparator($filePackageRelativePath));
                        if (! $file) {
                            $this->logger->warning("Expected discovered file at {relativePath} not found in package {packageName}", [
                                'relativePath' => $filePackageRelativePath,
                                'packageName'  => $dependency->getPackageName(),
                            ]);
                        } else {
                            $file->addAutoloaderType('files');
                            $file->setDoPrefix(true);
                        }

                        $visited = [];
                        $this->markIncludedFilesRecursive($filePackageAbsolutePath, $dependency, $dependencyPackageAbsolutePath, $visited);
                    }
                    break;
                case 'classmap':
                    $autoloadKeyPaths = array_map(
                        fn(string $path) => $dependencyPackageAbsolutePath . '/' . ltrim($path, '/'),
                        (array) $value
                    );
                    foreach ($autoloadKeyPaths as $autoloadKeyPath) {
                        if (! $this->filesystem->exists($autoloadKeyPath)) {
                            $this->logger->warning(
                                "Skipping non-existent autoload path in {packageName}: {path}",
                                [ 'packageName' => $dependency->getPackageName(), 'path' => $autoloadKeyPath ]
                            );
                            continue;
                        }
                        $classMapGenerator->scanPaths(
                            $this->filesystem->makeAbsolute($autoloadKeyPath),
                            $excluded,
                            $autoloadType,
                            $namespace,
                            $excludedDirs,
                        );
                    }
                    $this->processClassmapFiles($classMapGenerator, $dependency, $autoloaderType);
                    break;
                case 'psr-0':
                case 'psr-4':
                    foreach ((array) $value as $namespace => $namespaceRelativePaths) {
                        $psrPaths = array_map(
                            fn(string $path) => $dependencyPackageAbsolutePath . '/' . ltrim($path, '/'),
                            (array) $namespaceRelativePaths
                        );

                        foreach ($psrPaths as $autoloadKeyPath) {
                            if (! $this->filesystem->exists($autoloadKeyPath)) {
                                $this->logger->warning(
                                    "Skipping non-existent autoload path in {packageName}: {path}",
                                    [ 'packageName' => $dependency->getPackageName(), 'path' => $autoloadKeyPath ]
                                );
                                continue;
                            }
                            $absolutePath = $this->filesystem->makeAbsolute($autoloadKeyPath);
                            if (str_starts_with($absolutePath, 'mem://')) {
                                $absolutePath = new SplFileInfo($absolutePath);
                            }
                            $classMapGenerator->scanPaths(
                                $absolutePath,
                                $excluded,
                                $autoloadType,
                                $namespace,
                                $excludedDirs,
                            );
                            $this->processClassmapFiles($classMapGenerator, $dependency, $autoloaderType);
                        }
                    }
                    break;
                default:
                    $this->logger->warning('Unexpected autoloader type');
                    // TODO: include everything;
                    break;
            }
        }
    }

    /**
     * @param string $absoluteFilePath
     * @param ComposerPackage $dependency
     * @param string $packageAbsolutePath
     * @param array<string,bool> $visited
     *
     * @return void
     * @throws FilesystemException
     */
    private function markIncludedFilesRecursive(
        string $absoluteFilePath,
        ComposerPackage $dependency,
        string $packageAbsolutePath,
        array &$visited
    ): void {
        if (isset($visited[$absoluteFilePath])) {
            return;
        }
        $visited[$absoluteFilePath] = true;

        if (!$this->filesystem->exists($absoluteFilePath)) {
            return;
        }

        $contents = $this->filesystem->read($absoluteFilePath);
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        try {
            $ast = $parser->parse($contents);
        } catch (\PhpParser\Error $e) {
            $this->logger->warning('Could not parse {file}: {message}', [
                'file' => $absoluteFilePath, 'message' => $e->getMessage(),
            ]);
            return;
        }

        if (is_null($ast)) {
            $this->logger->warning('Parsed {file} return null', [
                'file' => $absoluteFilePath,
            ]);
            return;
        }

        $nodeFinder = new NodeFinder();
        $includeNodes = $nodeFinder->findInstanceOf($ast, \PhpParser\Node\Expr\Include_::class);
        $includingDir = dirname($absoluteFilePath);

        foreach ($includeNodes as $node) {
            $resolvedPath = $this->resolveIncludePath($node->expr, $includingDir);
            if ($resolvedPath === null) {
                $this->logger->debug('Cannot statically resolve include expression in {file}', [
                    'file' => $absoluteFilePath,
                ]);
                continue;
            }

            if (!str_starts_with($resolvedPath, $packageAbsolutePath)) {
                continue;
            }

            $relPath = $this->filesystem->getRelativePath($packageAbsolutePath, $resolvedPath);
            $file = $dependency->getFile(FileSystem::normalizeDirSeparator($relPath));
            if ($file) {
                $file->addAutoloaderType('files');
                $file->setDoPrefix(true);
            }

            $this->markIncludedFilesRecursive($resolvedPath, $dependency, $packageAbsolutePath, $visited);
        }
    }

    private function resolveIncludePath(\PhpParser\Node\Expr $expr, string $includingDir): ?string
    {
        if ($expr instanceof \PhpParser\Node\Scalar\String_) {
            $path = $expr->value;
            return str_starts_with($path, '/') ? $path : $includingDir . '/' . $path;
        }

        if ($expr instanceof \PhpParser\Node\Expr\BinaryOp\Concat) {
            $left = $expr->left;
            $right = $expr->right;

            $base = null;
            if ($left instanceof \PhpParser\Node\Scalar\MagicConst\Dir) {
                $base = $includingDir;
            } elseif ($left instanceof \PhpParser\Node\Expr\FuncCall
                && $left->name instanceof \PhpParser\Node\Name
                && strtolower((string) $left->name) === 'dirname'
            ) {
                $base = $includingDir;
            }

            if ($base !== null && $right instanceof \PhpParser\Node\Scalar\String_) {
                return $base . $right->value;
            }
        }

        return null;
    }

    protected function processClassmapFiles(ClassMapGenerator $classMapGenerator, ComposerPackage $dependency, string $autoloaderType): void
    {
        $classMap = $classMapGenerator->getClassMap();
        $classMapPaths = $classMap->getMap();
        foreach ($classMapPaths as $fileAbsolutePath) {
            /**
             * This will never be null because we have been looking inside this path!
             *
             * @var string $packageAbsolutePath
             */
            $packageAbsolutePath = $dependency->getPackageAbsolutePath();
            $relativePath = $this->filesystem->getRelativePath($packageAbsolutePath, $fileAbsolutePath);
            $file = $dependency->getFile($relativePath);
            if (!$file) {
                $this->logger->warning("Expected discovered file at {relativePath} not found in package {packageName}", [
                    'relativePath' => $relativePath,
                    'packageName' => $dependency->getPackageName(),
                ]);
            } else {
                /**
                 * We are assuming at this point that we will rename all autoloaded PHP files. Rules will be applied later.
                 *
                 * @see MarkSymbolsForRenaming
                 */
                $file->setDoPrefix(true);
                $file->addAutoloaderType($autoloaderType);
            }
        }
    }
}
