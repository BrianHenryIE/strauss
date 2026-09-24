<?php
/**
 * Applies the changes to each file.
 *
 * By the time the pipeline reaches here, every change has already been determined: {@see FileSymbolScanner} parsed
 * each file once into a {@see FileCodeMap}, and {@see ChangePlanner} recorded the edits on that map. This class only
 * reads the file, applies the planned edits, and writes it back.
 *
 * {@see self::replaceInString()} remains for content that did not pass through the pipeline (tests, Composer's
 * generated autoload files, project files): it analyses and plans on demand, still parsing each file only once.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\PrefixerConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Files\FileCodeMap;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\PhpFileAnalyzer;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Composer\ClassMapGenerator\ClassMapGenerator;
use Exception;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Prefixer
{
    use LoggerAwareTrait;

    /**
     * The prefix Strauss applies to its own dependencies when building `strauss.phar`.
     *
     * Composer's autoload files are then written by Strauss's bundled, prefixed, copy of Composer, so classnames
     * it hardcodes (e.g. `Composer\InstalledVersions`) appear with this prefix and must be stripped before applying
     * the project's prefix.
     */
    const SELF_NAMESPACE_PREFIX = 'BrianHenryIE\\Strauss\\';

    protected PrefixerConfigInterface $config;

    protected FileSystem $filesystem;

    /**
     * array<$filePath, $package> or null if the file is not from a dependency (i.e. a project file).
     *
     * @var array<string, ?ComposerPackage>
     */
    protected array $changedFiles = array();

    protected ?PhpFileAnalyzer $analyzer = null;

    protected ?ChangePlanner $planner = null;

    public function __construct(
        PrefixerConfigInterface $config,
        FileSystem              $filesystem,
        ?LoggerInterface        $logger = null
    ) {
        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param DiscoveredSymbols $discoveredSymbols
     * @param array<FileBase> $files
     *
     * @throws FilesystemException
     */
    public function replaceInFiles(DiscoveredSymbols $discoveredSymbols, array $files): void
    {
        foreach ($files as $file) {
            $this->replaceInFile($discoveredSymbols, $file);
        }
    }

    protected function replaceInFile(DiscoveredSymbols $discoveredSymbols, FileBase $file): void
    {
        if (!$this->config->isTargetDirectoryVendor()
            && !$file->isDoCopy()
        ) {
            return;
        }

        if (!$file->getDoUpdate()) {
            return;
        }

        if ($this->filesystem->directoryExists($file->getTargetAbsolutePath())) {
            $this->logger->debug("is_dir() / nothing to do : {targetAbsolutePath}", [
                'targetAbsolutePath' => $file->getTargetAbsolutePath()
            ]);
            return;
        }

        if (!$file->isPhpFile()) {
            return;
        }

        if (!$this->filesystem->fileExists($file->getTargetAbsolutePath())) {
            // Some files are only sometimes present.
            if (in_array($file->getTargetAbsolutePath(), [
                $this->config->getAbsoluteTargetDirectory() . '/composer/autoload_files.php',
                $this->config->getAbsoluteTargetDirectory() . '/composer/platform_check.php',
            ], true)) {
                return;
            }
            $this->logger->warning("Expected file does not exist: {targetAbsolutePath}", [
                'targetAbsolutePath' => $file->getTargetAbsolutePath()
            ]);
            return;
        }

        $this->logger->debug("Updating contents of file: {targetAbsolutePath}", [
            'targetAbsolutePath' => $file->getTargetAbsolutePath()
        ]);

        /**
         * Throws an exception, but unlikely to happen.
         */
        $contents = $this->filesystem->read($file->getTargetAbsolutePath());

        $updatedContents = $this->replaceInString($discoveredSymbols, $contents, $file);

        if ($updatedContents !== $contents) {
            // TODO: diff here and debug log.
            $file->setDidUpdate();
            $this->filesystem->write($file->getTargetAbsolutePath(), $updatedContents);
            $this->logger->info("Updated contents of file::::{targetAbsolutePath}", [
                'targetAbsolutePath' => $file->getTargetAbsolutePath()
            ]);
        } else {
            $this->logger->debug("No changes to file::::{targetAbsolutePath}", [
                'targetAbsolutePath' => $file->getTargetAbsolutePath()
            ]);
        }
    }

    /**
     * @param DiscoveredSymbols $discoveredSymbols
     * @param DiscoveredFiles $projectFiles
     *
     * @return void
     * @throws FilesystemException
     */
    public function replaceInProjectFiles(DiscoveredSymbols $discoveredSymbols, DiscoveredFiles $projectFiles): void
    {
        $phpFiles = array_filter(
            $projectFiles->getFiles(),
            fn($file) => $file->isPhpFile()
        );

        foreach ($phpFiles as $file) {
            $fileAbsolutePath = $file->getSourcePath();

            $relativeFilePath = $this->filesystem->getRelativePath(dirname($this->config->getAbsoluteTargetDirectory()), $fileAbsolutePath);

            if ($this->filesystem->directoryExists($fileAbsolutePath)) {
                $this->logger->debug("is_dir() / nothing to do::::{relativeFilePath}", [
                    'relativeFilePath' => $relativeFilePath
                ]);
                continue;
            }

            if (!$this->filesystem->fileExists($fileAbsolutePath)) {
                // Some files are only sometimes present.
                if (in_array($fileAbsolutePath, [
                    $this->config->getAbsoluteTargetDirectory() . '/composer/autoload_files.php',
                    $this->config->getAbsoluteTargetDirectory() . '/composer/platform_check.php',
                    ], true)) {
                    continue;
                }
                $this->logger->warning("Expected file does not exist::::{relativeFilePath}", [
                    'relativeFilePath' => $relativeFilePath
                ]);
                continue;
            }

            $this->logger->debug("Updating contents of file (project)::::{fileAbsolutePath}", [
                'fileAbsolutePath' => $fileAbsolutePath,
            ]);

            // Throws an exception, but unlikely to happen.
            $contents = $this->filesystem->read($fileAbsolutePath);

            $updatedContents = $this->replaceInString($discoveredSymbols, $contents, $file);

            if ($updatedContents !== $contents) {
                $this->changedFiles[$fileAbsolutePath] = null;
                $this->filesystem->write($fileAbsolutePath, $updatedContents);
                $this->logger->info('Updated contents of file::::' . $relativeFilePath);
            } else {
                $this->logger->debug('No changes to file::::' . $relativeFilePath);
            }
        }
    }

    /**
     * Apply the planned edits to the contents.
     *
     * When the file has a code map for these contents with planned edits, only those edits are applied. Otherwise
     * (content that did not pass through the pipeline) the contents are analysed and planned now.
     *
     * @param DiscoveredSymbols $discoveredSymbols
     * @param string $contents
     *
     * @throws Exception
     */
    public function replaceInString(DiscoveredSymbols $discoveredSymbols, string $contents, ?FileBase $file = null): string
    {
        $codeMap = $this->getPlannedCodeMap($discoveredSymbols, $contents, $file);

        if (is_null($codeMap)) {
            return $contents;
        }

        return $codeMap->applyPlannedEdits($contents);
    }

    /**
     * The file's code map, with edits planned, for the given contents.
     *
     * @return ?FileCodeMap Null when the contents cannot be parsed.
     */
    protected function getPlannedCodeMap(DiscoveredSymbols $discoveredSymbols, string $contents, ?FileBase $file): ?FileCodeMap
    {
        $fileAbsolutePath = is_null($file) ? null : $file->getTargetAbsolutePath();

        $codeMap = $file instanceof File ? $file->getCodeMap() : null;

        if ($codeMap && !$codeMap->matchesContent($contents)) {
            $this->logger->debug("Code map does not match the file contents, re-analysing::::{filePath}", [
                'filePath' => $fileAbsolutePath ?? 'file',
            ]);
            $codeMap = null;
        }

        if (is_null($codeMap)) {
            $this->logger->info("Parsing::::{filePath}", [
                'filePath' => $fileAbsolutePath ?? 'file',
            ]);

            $codeMap = $this->getAnalyzer()->analyze($contents, $fileAbsolutePath);

            if (is_null($codeMap)) {
                // This happens in template files, E.g `x.blade.php`.
                $this->logger->warning("Skipping Prefixing in {filePath} due to parse error: {errorMessage}", [
                    'filePath' => $fileAbsolutePath ?? 'file',
                    'errorMessage' => $this->getAnalyzer()->getLastErrorMessage(),
                ]);
                return null;
            }

            if ($file instanceof File) {
                $file->setCodeMap($codeMap);
            }
        }

        if (!$codeMap->isPlanned()) {
            $this->logger->debug("Planning changes::::{filePath}", [
                'filePath' => $fileAbsolutePath ?? 'file',
            ]);
            $this->getPlanner()->plan($discoveredSymbols, $codeMap, $file);
        }

        return $codeMap;
    }

    /**
     * TODO: This should be a function on {@see DiscoveredFiles}.
     *
     * @return array<string, ComposerPackage>
     */
    public function getModifiedFiles(): array
    {
        return $this->changedFiles;
    }

    public function prefixComposerAutoloadFiles(string $absoluteDirectory, DiscoveredFiles $discoveredFiles): void
    {
        $this->logger->debug("Prefixing the Composer autoload files in {path}.", [
            'path' => $absoluteDirectory,
        ]);

        $composerFilePaths = [
            'InstalledVersions.php',
            'autoload_classmap.php',
            'autoload_files.php',
            'autoload_namespaces.php',
            'autoload_psr4.php',
            'autoload_real.php',
            'autoload_static.php',
            'ClassLoader.php',
            'installed.json',
            'installed.php',
            'InstalledVersions.php',
            'platform_check.php',
        ];

        $composerFiles = [];

        foreach ($composerFilePaths as $filePath) {
            if ($this->filesystem->fileExists($absoluteDirectory . '/composer/' . $filePath)) {
                $file = new File(
                    $absoluteDirectory . '/composer/' . $filePath,
                    $filePath,
                    $absoluteDirectory . '/composer/' . $filePath,
                );
                $discoveredFiles->add($file);
                $composerFiles[ $filePath ] = $file;
            }
        }

        // During `--dry-run`, until Composer fully supports streamwrappers.
        if ($this->config->isDryRun()) {
            return;
        }

        $classMapGenerator = new ClassMapGenerator();
        $classMapGenerator->scanPaths(array_map(
            fn(File $file) => new \SplFileInfo(
                $this->filesystem->makeAbsolute(
                    $file->getTargetAbsolutePath()
                )
            ),
            $composerFiles
        ));

        $classMap = $classMapGenerator->getClassMap();

        $discoveredSymbols = new DiscoveredSymbols();

        foreach ($classMap->getMap() as $fqdnClass => $absolutePath) {
            $namespaceString = $this->getNamespaceFromFqdn($fqdnClass);
            if (!$namespaceString) {
                continue;
            }
            if ($discoveredSymbols->getNamespace($namespaceString)) {
                continue;
            }
            $namespaceSymbol = new NamespaceSymbol($namespaceString);

            $innerPattern = ! $this->config->getNamespacePrefix() ? ''
                : sprintf(
                    '|(%s\\\\)',
                    str_replace('\\', '\\\\', rtrim($this->config->getNamespacePrefix(), '\\'))
                );

            $pattern = sprintf(
                '#^(%s)%s*#',
                str_replace('\\', '\\\\', self::SELF_NAMESPACE_PREFIX),
                $innerPattern
            );

            $localReplacement = $this->config->getNamespacePrefix() . '\\'
                                . preg_replace(
                                    $pattern,
                                    '',
                                    $namespaceString
                                );
            $namespaceSymbol->setLocalReplacement(
                $localReplacement
            );
            $namespaceSymbol->setDoRename(true);
            $discoveredSymbols->add($namespaceSymbol);
        }

        $globalNamespace = new NamespaceSymbol('\\');
        foreach ($classMap->getMap() as $fqdnClass => $absolutePath) {
            $namespaceString = $this->getNamespaceFromFqdn($fqdnClass);
            $namespace = $discoveredSymbols->getNamespace($namespaceString ?? '\\') ?? $globalNamespace;
            $composerFile = $composerFiles[ basename($absolutePath) ];
            $classLoaderSymbol = new ClassSymbol(
                $fqdnClass,
                $composerFile,
                $namespace,
            );
            $classLoaderSymbol->setDoRename(true);
            $discoveredSymbols->add($classLoaderSymbol);

            /**
             * When Strauss itself has been prefixed (i.e. `strauss.phar`), its bundled Composer's hardcoded
             * `'Composer\InstalledVersions'` classmap entry has been rewritten to
             * `'BrianHenryIE\Strauss\Composer\InstalledVersions'`, although the `InstalledVersions.php` copied into
             * the target directory still declares `Composer\InstalledVersions`. Register the self-prefixed name too so
             * the classmap entry is renamed to the project's prefix.
             *
             * @see \Composer\Autoload\AutoloadGenerator::dump() `$classMap->addClass('Composer\InstalledVersions', ...)`
             * @see https://github.com/BrianHenryIE/strauss/issues/301
             */
            if (is_null($namespaceString) || str_starts_with($namespaceString, self::SELF_NAMESPACE_PREFIX)) {
                continue;
            }
            $selfPrefixedNamespaceString = self::SELF_NAMESPACE_PREFIX . $namespaceString;
            $selfPrefixedNamespace = $discoveredSymbols->getNamespace($selfPrefixedNamespaceString);
            if (is_null($selfPrefixedNamespace)) {
                $selfPrefixedNamespace = new NamespaceSymbol($selfPrefixedNamespaceString);
                $selfPrefixedNamespace->setLocalReplacement($namespace->getLocalReplacement());
                $selfPrefixedNamespace->setDoRename(true);
                $discoveredSymbols->add($selfPrefixedNamespace);
            }
            $selfPrefixedClassSymbol = new ClassSymbol(
                self::SELF_NAMESPACE_PREFIX . $fqdnClass,
                $composerFile,
                $selfPrefixedNamespace,
            );
            $selfPrefixedClassSymbol->setDoRename(true);
            $discoveredSymbols->add($selfPrefixedClassSymbol);
        }

        $this->replaceInFiles($discoveredSymbols, $discoveredFiles->getFiles());
    }

    protected function getNamespaceFromFqdn(string $namespacedString): ?string
    {
        if (1 === preg_match('/(.*)(\\\\[^\\\\]*$)/', $namespacedString, $output_array)) {
            return $output_array[1];
        }
        return null;
    }

    protected function getAnalyzer(): PhpFileAnalyzer
    {
        return $this->analyzer ??= new PhpFileAnalyzer($this->logger);
    }

    protected function getPlanner(): ChangePlanner
    {
        return $this->planner ??= new ChangePlanner($this->logger);
    }
}
