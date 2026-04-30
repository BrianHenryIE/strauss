<?php
/**
 * Use each package's autoload key to determine which files in the package are to be prefixed, apply exclusion rules.
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\DependenciesCollection;
use BrianHenryIE\Strauss\Config\AutoloadFilesEnumeratorConfigInterface;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use Composer\ClassMapGenerator\ClassMapGenerator;
use League\Flysystem\FilesystemException;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use SplFileInfo;

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

    public function scanForAutoloadedFiles(DependenciesCollection $dependencies): void
    {
        foreach ($dependencies as $dependency) {
            $this->scanPackage($dependency);
        }
    }

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
            return 'exclude-from-classmap' !== $type;
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
            $relativePath = $this->filesystem->getRelativePath($dependency->getPackageAbsolutePath(), $fileAbsolutePath);
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
