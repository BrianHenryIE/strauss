<?php
/**
 * Applies the changes to each file.
 *
 * By the time the pipeline reaches here, every change has already been determined: {@see FileSymbolScanner} parsed
 * each file once into a {@see FileCodeMap}, and {@see ChangePlanner} recorded the edits on the {@see File}. This class
 * only reads the file, applies the planned edits, writes it back, and gives the file a code map for its new contents.
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

    /**
     * The PHP files Composer writes to its `composer` directory.
     */
    const COMPOSER_RUNTIME_FILES = [
        'autoload_classmap.php',
        'autoload_files.php',
        'autoload_namespaces.php',
        'autoload_psr4.php',
        'autoload_real.php',
        'autoload_static.php',
        'ClassLoader.php',
        'installed.php',
        'InstalledVersions.php',
        'platform_check.php',
    ];

    protected PrefixerConfigInterface $config;

    protected FileSystem $filesystem;

    /**
     * array<$filePath, $package> or null if the file is not from a dependency (i.e. a project file).
     *
     * @var array<string, ?ComposerPackage>
     */
    protected array $changedFiles = array();

    /**
     * The files whose targets {@see self::writeFromSource()} wrote, keyed by source path.
     *
     * @var array<string, true>
     */
    protected array $writtenFromSource = [];

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

    /**
     * Write the file's target directly from its source with the planned edits applied, so the file is written
     * once rather than copied and then read back, edited and written again.
     *
     * Only for a PHP file which is to be copied and updated, has edits planned against its source's contents, and
     * whose source is not reached through a symlink (copying has its own rules for those). Any other file is left
     * to be copied, and updated by {@see self::replaceInFiles()} as before.
     *
     * Returns whether the target was written. When it was, {@see self::replaceInFiles()} skips the file.
     *
     * @throws FilesystemException
     */
    public function writeFromSource(DiscoveredSymbols $discoveredSymbols, File $file): bool
    {
        if ($this->config->isTargetDirectoryVendor()
            || !$file->isDoCopy()
            || !$file->getDoUpdate()
            || !$file->isPhpFile()
            || empty($file->getPlannedEdits())
        ) {
            return false;
        }

        $sourceAbsolutePath = $file->getSourcePath();
        $targetAbsolutePath = $file->getTargetAbsolutePath();

        if ($sourceAbsolutePath === $targetAbsolutePath || $this->filesystem->isSymlinked($sourceAbsolutePath)) {
            return false;
        }

        $codeMap = $file->getCodeMap();
        $contents = $this->filesystem->read($sourceAbsolutePath);

        // Changed since it was scanned: copy it, and let it be analysed again when it is updated.
        if (is_null($codeMap) || !$codeMap->matchesContent($contents)) {
            return false;
        }

        $this->logger->debug("Updating contents of file: {targetAbsolutePath}", [
            'targetAbsolutePath' => $targetAbsolutePath
        ]);

        $updatedContents = $this->replaceInString($discoveredSymbols, $contents, $file);

        $this->filesystem->write($targetAbsolutePath, $updatedContents);
        $this->writtenFromSource[$sourceAbsolutePath] = true;

        if ($updatedContents !== $contents) {
            $file->setDidUpdate();
            $this->logger->info("Updated contents of file::::{targetAbsolutePath}", [
                'targetAbsolutePath' => $targetAbsolutePath
            ]);
        } else {
            $this->logger->debug("No changes to file::::{targetAbsolutePath}", [
                'targetAbsolutePath' => $targetAbsolutePath
            ]);
        }

        return true;
    }

    protected function replaceInFile(DiscoveredSymbols $discoveredSymbols, FileBase $file): void
    {
        // Its target was written, already updated, when the files were copied.
        if (isset($this->writtenFromSource[$file->getSourcePath()])) {
            return;
        }

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
     * Consumes the plan: afterwards the file has no planned edits and its code map describes the returned contents,
     * ready for a later pipeline phase to plan a new set of changes against it.
     *
     * @param DiscoveredSymbols $discoveredSymbols
     * @param string $contents
     *
     * @throws Exception
     */
    public function replaceInString(DiscoveredSymbols $discoveredSymbols, string $contents, ?FileBase $file = null): string
    {
        $codeMap = $this->getCodeMap($contents, $file);

        if (is_null($codeMap)) {
            return $contents;
        }

        $edits = $file instanceof File ? $file->getPlannedEdits() : null;

        if (is_null($edits)) {
            $this->logger->debug("Planning changes::::{filePath}", [
                'filePath' => is_null($file) ? 'file' : $file->getTargetAbsolutePath(),
            ]);
            $edits = $this->getPlanner()->plan($discoveredSymbols, $codeMap, $file);
        }

        if ($file instanceof File) {
            $file->setPlannedEdits(null);
        }

        if (empty($edits)) {
            return $contents;
        }

        $edits = $this->sortFilterEdits($edits);

        $updatedContents = $this->applyEdits($contents, $edits);

        if ($file instanceof File) {
            $file->setCodeMap($this->updateCodeMap($codeMap, $edits, $updatedContents));
        }

        return $updatedContents;
    }

    /**
     * The file's code map for the given contents, analysing them now if the file has none that matches.
     *
     * @return ?FileCodeMap Null when the contents cannot be parsed.
     */
    protected function getCodeMap(string $contents, ?FileBase $file): ?FileCodeMap
    {
        $fileAbsolutePath = is_null($file) ? null : $file->getTargetAbsolutePath();

        $codeMap = $file instanceof File ? $file->getCodeMap() : null;

        if ($codeMap && $codeMap->matchesContent($contents)) {
            return $codeMap;
        }

        if ($codeMap) {
            $this->logger->debug("Code map does not match the file contents, re-analysing::::{filePath}", [
                'filePath' => $fileAbsolutePath ?? 'file',
            ]);
        }

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
            // Also discards any edits planned against the previous map.
            $file->setCodeMap($codeMap);
        }

        return $codeMap;
    }

    /**
     * Sort edits ascending by start and drop overlaps, keeping the earliest-starting edit.
     *
     * @param array<array{start:int,end:int,replacement:string}> $edits
     *
     * @return array<array{start:int,end:int,replacement:string}>
     */
    protected function sortFilterEdits(array $edits): array
    {
        usort($edits, fn($a, $b) => $a['start'] <=> $b['start']);

        $previousEnd = -1;
        return array_values(array_filter(
            $edits,
            function (array $edit) use (&$previousEnd): bool {
                if ($edit['start'] < $previousEnd) {
                    return false;
                }
                $previousEnd = max($previousEnd, $edit['end']);
                return true;
            }
        ));
    }

    /**
     * Apply text replacements to the contents.
     *
     * @param array<array{start:int,end:int,replacement:string}> $edits Ascending by start, not overlapping: {@see self::sortFilterEdits()}.
     *
     * @return string The updated contents.
     */
    protected function applyEdits(string $contents, array $edits): string
    {
        // Rebuild the string in one pass rather than `substr_replace()` per edit, which is quadratic for large files.
        $parts = [];
        $cursor = 0;
        foreach ($edits as $edit) {
            $parts[] = substr($contents, $cursor, $edit['start'] - $cursor);
            $parts[] = $edit['replacement'];
            $cursor = $edit['end'];
        }
        $parts[] = substr($contents, $cursor);

        return implode('', $parts);
    }

    /**
     * Build the code map describing the contents after the edits were applied. The given map is not modified.
     *
     * A location whose range exactly matches an edit gets the replacement as its new text; edits strictly inside a
     * location (e.g. a classname inside a comment) are spliced into its text; a location partially overlapped by an
     * edit can no longer be tracked and is dropped from the map.
     *
     * @param FileCodeMap $codeMap The map of the contents before the edits.
     * @param array<array{start:int,end:int,replacement:string}> $edits Ascending by start, not overlapping: {@see self::sortFilterEdits()}.
     * @param string $updatedContents The contents after the edits: {@see self::applyEdits()}.
     */
    protected function updateCodeMap(FileCodeMap $codeMap, array $edits, string $updatedContents): FileCodeMap
    {
        $updatedLocations = [];
        foreach ($codeMap->getLocations() as $originalLocation) {
            $location = clone $originalLocation;
            $shift = 0;
            $newText = $location->getText();
            $modified = false;
            $dropped = false;

            foreach ($edits as $edit) {
                $delta = strlen($edit['replacement']) - ($edit['end'] - $edit['start']);

                if ($edit['end'] <= $location->getStart()) {
                    // Entirely before: shift.
                    $shift += $delta;
                } elseif ($edit['start'] >= $location->getEnd()) {
                    // Entirely after: no effect.
                    continue;
                } elseif ($edit['start'] === $location->getStart() && $edit['end'] === $location->getEnd()) {
                    $newText = $edit['replacement'];
                    $modified = true;
                } elseif ($edit['start'] >= $location->getStart() && $edit['end'] <= $location->getEnd()) {
                    // Strictly inside: splice into the text. Edits are ascending, but any earlier inside-edits
                    // changed the text length, so adjust by the accumulated inner delta.
                    $innerOffset = $edit['start'] - $location->getStart() + (strlen($newText) - $location->getLength());
                    $newText = substr_replace(
                        $newText,
                        $edit['replacement'],
                        $innerOffset,
                        $edit['end'] - $edit['start']
                    );
                    $modified = true;
                } else {
                    // Partial overlap: position can no longer be tracked.
                    $dropped = true;
                    break;
                }
            }

            if ($dropped) {
                continue;
            }

            $location->setStart($location->getStart() + $shift);
            if ($modified) {
                $location->setText($newText);
                // The written text changed; what it now resolves to is not re-derived.
                $location->setResolvedName(null);
            }
            $updatedLocations[] = $location;
        }

        return new FileCodeMap(
            $updatedLocations,
            $updatedContents,
            $codeMap->getDefinitions(),
            $codeMap->getNamespaceNames()
        );
    }

    /**
     * TODO: This should be a function on {@see DiscoveredFiles}.
     *
     * @return array<string, ?ComposerPackage> Null for a file which is not from a dependency (i.e. a project file).
     */
    public function getModifiedFiles(): array
    {
        return $this->changedFiles;
    }

    /**
     * Prefix Composer's own files in the target directory's `composer` directory: `autoload_real.php`,
     * `ClassLoader.php`, `InstalledVersions.php` etc.
     *
     * References to those classes in the packages' files have already been changed: the pipeline plans every
     * file with {@see self::getComposerRuntimeSymbols()} included.
     *
     * @throws FilesystemException
     */
    public function prefixComposerAutoloadFiles(string $absoluteDirectory): void
    {
        $this->logger->debug("Prefixing the Composer autoload files in {path}.", [
            'path' => $absoluteDirectory,
        ]);

        // During `--dry-run`, until Composer fully supports streamwrappers.
        if ($this->config->isDryRun()) {
            return;
        }

        $composerFiles = $this->getComposerRuntimeFiles($absoluteDirectory . '/composer');

        $this->replaceInFiles($this->getComposerRuntimeSymbols($composerFiles), $composerFiles);
    }

    /**
     * The files Composer writes to its `composer` directory, which exist in the given directory.
     *
     * @param string $composerDirectory E.g. `/path/to/project/vendor/composer`.
     *
     * @return array<string, File> Keyed by filename.
     */
    public function getComposerRuntimeFiles(string $composerDirectory): array
    {
        $composerFiles = [];

        foreach (self::COMPOSER_RUNTIME_FILES as $fileName) {
            $filePath = $composerDirectory . '/' . $fileName;
            if ($this->filesystem->fileExists($filePath)) {
                $composerFiles[$fileName] = new File($filePath, $fileName, $filePath);
            }
        }

        return $composerFiles;
    }

    /**
     * The classes and namespaces Composer itself provides at runtime – `Composer\InstalledVersions`,
     * `Composer\Autoload\ClassLoader` and the generated autoloader classes – marked for renaming with the
     * project's prefix.
     *
     * Packages use these (effectively `composer-runtime-api`) without requiring them, so every file is assumed to
     * be able to refer to them.
     *
     * @param array<string, File> $composerFiles Keyed by filename: {@see self::getComposerRuntimeFiles()}.
     */
    public function getComposerRuntimeSymbols(array $composerFiles): DiscoveredSymbols
    {
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

        return $discoveredSymbols;
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
