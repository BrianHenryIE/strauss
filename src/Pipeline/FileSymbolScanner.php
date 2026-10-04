<?php
/**
 * The purpose of this class is only to find changes that should be made.
 * i.e. classes and namespaces to change.
 * Those recorded are updated in a later step.
 *
 * Each PHP file is parsed exactly once, by {@see PhpFileAnalyzer}, producing a {@see FileCodeMap} of what the file
 * defines and every symbol it uses. The map is stored on the {@see File} for {@see ChangePlanner} and {@see Prefixer}.
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\FileSymbolScannerConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Files\FileCodeMap;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\AnalysisCache;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\PhpFileAnalyzer;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\SymbolDefinition;
use BrianHenryIE\Strauss\Pipeline\Parallel\ParallelisationException;
use BrianHenryIE\Strauss\Pipeline\Parallel\WorkerPool;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\EnumSymbol;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\InterfaceSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use BrianHenryIE\Strauss\Types\TraitSymbol;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class FileSymbolScanner
{
    use LoggerAwareTrait;

    /**
     * @var array<class-string<DiscoveredSymbol>,string>
     */
    private const SYMBOL_LOG_TYPES = [
        ClassSymbol::class => 'class',
        ConstantSymbol::class => 'constant',
        EnumSymbol::class => 'enum',
        FunctionSymbol::class => 'function',
        InterfaceSymbol::class => 'interface',
        NamespaceSymbol::class => 'namespace',
        TraitSymbol::class => 'trait',
    ];

    protected DiscoveredSymbols $discoveredSymbols;

    protected FileSystem $filesystem;

    protected FileSymbolScannerConfigInterface $config;

    /** @var string[] */
    protected array $builtIns = [];

    protected ?PhpFileAnalyzer $analyzer = null;

    /**
     * When set, files are analysed in worker processes before the (sequential) symbol discovery.
     */
    protected ?WorkerPool $workerPool = null;

    /**
     * When set, a file whose contents were analysed on an earlier run is not parsed again.
     */
    protected ?AnalysisCache $analysisCache = null;

    /**
     * Results from the cache and the worker processes, keyed by source path: the map, or null with the parse
     * error message.
     *
     * @var array<string, array{0:?FileCodeMap, 1:?string}>
     */
    protected array $analysisResults = [];

    /**
     * The files already found not to be in the cache, keyed by source path, so they are not looked up a second
     * time.
     *
     * @var array<string, true>
     */
    protected array $missedCacheKeys = [];

    /**
     * @var array<string,bool>
     */
    protected array $loggedSymbols = [];

    /**
     * @var array<string,bool>
     */
    protected array $builtInsLookup = [];

    /**
     * FileScanner constructor.
     */
    public function __construct(
        FileSymbolScannerConfigInterface $config,
        DiscoveredSymbols $discoveredSymbols,
        FileSystem $filesystem,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->discoveredSymbols = $discoveredSymbols;
        $this->filesystem = $filesystem;
        $this->logger = $logger ?? new NullLogger();
    }

    public function setWorkerPool(?WorkerPool $workerPool): void
    {
        $this->workerPool = $workerPool;
    }

    public function setAnalysisCache(?AnalysisCache $analysisCache): void
    {
        $this->analysisCache = $analysisCache;
    }

    protected function add(DiscoveredSymbol $symbol, ?FileBase $file = null): void
    {
        if (in_array($symbol->getOriginalFqdnName(), $this->getBuiltIns())) {
            $this->logger->debug('Skipping built-in symbol {symbolName}, possible a polyfill.', [
                'symbolName' => $symbol->getOriginalLocalName(),
            ]);
            return;
        }

        $this->discoveredSymbols->add($symbol);

        if ($file instanceof FileWithDependency) {
            $file->getDependency()->addDiscoveredSymbol($symbol);
        }

        $level = in_array($symbol->getOriginalFqdnName(), $this->loggedSymbols) ? 'debug' : 'info';
        $newText = in_array($symbol->getOriginalFqdnName(), $this->loggedSymbols) ? '' : 'new ';

        $this->loggedSymbols[] = $symbol->getOriginalFqdnName();
//        $this->loggedSymbols[$symbol->getOriginalFqdnName()] = true;

        $this->logger->log(
            $level,
            sprintf(
                "Found %s%s:::%s",
                $newText,
                // From `BrianHenryIE\Strauss\Types\TraitSymbol` -> `trait`
                strtolower(str_replace('Symbol', '', array_reverse(explode('\\', get_class($symbol)))[0])),
                $symbol->getOriginalFqdnName()
            )
        );
    }

    /**
     * @throws FilesystemException
     */
    public function findInFiles(DiscoveredFiles $files): DiscoveredSymbols
    {
        $packagesToPrefixLookup = array_fill_keys(array_keys($this->config->getPackagesToPrefix()), true);
        $projectDirectory = $this->config->getProjectAbsolutePath();

        $this->analyzeInParallel($files);

        foreach ($files->getFiles() as $file) {
            if ($file instanceof FileWithDependency
                && !in_array($file->getDependency()->getPackageName(), array_keys($this->config->getPackagesToPrefix()))) {
                    /**
                     * We will not prefix symbols found in this file because it is not in a default or listed package.
                     *
                     * TODO: Move this logic to {@see MarkSymbolsForRenaming}.
                     */
                    $file->setDoPrefix(false);
//                    continue;
            }

            if ($file instanceof FileWithDependency
                && !isset($packagesToPrefixLookup[$file->getDependency()->getPackageName()])
            ) {
                $doPrefix = false;
                $file->setDoPrefix($doPrefix);
            }

            $relativeFilePath =
                $file instanceof FileWithDependency
                    ? $file->getVendorRelativePath()
                    : $this->filesystem->getRelativePath($projectDirectory, $file->getSourcePath());

            if (!$file->isPhpFile()) {
                $file->setDoPrefix(false);
                $this->logger->debug("Skipping non-PHP file:::". $relativeFilePath);
                continue;
            }

            $this->logger->info("Scanning file:::" . $relativeFilePath);

            $package = $file instanceof FileWithDependency ? $file->getDependency() : null;

            if (isset($this->analysisResults[$file->getSourcePath()])) {
                [$codeMap, $errorMessage] = $this->analysisResults[$file->getSourcePath()];
                unset($this->analysisResults[$file->getSourcePath()]);
                if (is_null($codeMap)) {
                    $this->logParseFailure($file, $errorMessage);
                }
                $this->record($codeMap, $file, $package);
                continue;
            }

            $this->find(
                /**
                 * "one unreadable file cancels scanning of all remaining files with no per-file error handling."
                 * I think this is desirable, since we just ran the file list a moment ago, if something is unreadable, that's a show stopper.
                 */
                $this->filesystem->read($file->getSourcePath()),
                $file,
                $package
            );
        }

        if (!is_null($this->analysisCache)) {
            $this->logger->debug('Analysis cache: {hits} files read from the cache, {misses} parsed.', [
                'hits' => $this->analysisCache->getHits(),
                'misses' => $this->analysisCache->getMisses(),
            ]);
        }

        return $this->discoveredSymbols;
    }

    /**
     * As {@see PhpFileAnalyzer::analyze()} logs it when parsing in this process.
     */
    protected function logParseFailure(FileBase $file, ?string $errorMessage): void
    {
        $this->logger->warning('Failed to parse file {filePath} with error: {errorMessage}', [
            'filePath' => $file->getSourcePath(),
            'errorMessage' => $errorMessage ?? 'unknown',
        ]);
    }

    /**
     * Parse the PHP files in worker processes, so the loop in {@see self::findInFiles()} only records what they
     * found. The loop's order, and so the order symbols are discovered and logged in, is unchanged.
     *
     * Files with an entry in the analysis cache are not sent to the workers, and the workers add what they analyse
     * to the cache themselves.
     *
     * If the workers cannot be run, the files are parsed in this process as before.
     */
    protected function analyzeInParallel(DiscoveredFiles $files): void
    {
        if (is_null($this->workerPool)) {
            return;
        }

        /** @var array<string, string> $absolutePaths Keyed by source path. */
        $absolutePaths = [];
        /** @var array<string, array{0:?FileCodeMap, 1:?string}> $cachedResults Keyed by source path. */
        $cachedResults = [];
        foreach ($files->getFiles() as $file) {
            if (!$file->isPhpFile() || !($file instanceof File)) {
                continue;
            }
            $sourcePath = $file->getSourcePath();
            // With an empty cache the files are not read here only to be read again by the workers.
            if (!is_null($this->analysisCache) && $this->analysisCache->hasEntries()) {
                $contents = $this->filesystem->read($sourcePath);
                $cachedResult = $this->analysisCache->get($contents);
                if (!is_null($cachedResult)) {
                    $cachedResults[$sourcePath] = $cachedResult;
                    continue;
                }
                $this->missedCacheKeys[$sourcePath] = true;
            }
            $absolutePaths[$sourcePath] = $this->filesystem->makeAbsolute($sourcePath);
        }

        $this->analysisResults = $cachedResults;

        if (count($absolutePaths) <= $this->workerPool->getConfig()->getFilesPerChunk()) {
            $this->logger->debug('Too few files to analyse in parallel.');
            return;
        }

        $sourcePathsByAbsolutePath = array_flip($absolutePaths);

        try {
            $this->workerPool->analyze(
                array_values($absolutePaths),
                function (string $absolutePath, ?FileCodeMap $codeMap, ?string $errorMessage) use ($sourcePathsByAbsolutePath): void {
                    $this->analysisResults[$sourcePathsByAbsolutePath[$absolutePath]] = [$codeMap, $errorMessage];
                },
                is_null($this->analysisCache) || $this->analysisCache->isReadOnly()
                    ? null
                    : $this->analysisCache->getDirectory()
            );
        } catch (ParallelisationException $e) {
            $this->logger->warning('Could not analyse files in parallel, continuing in this process: {errorMessage}', [
                'errorMessage' => $e->getMessage(),
            ]);
            // What the workers returned before failing is not used.
            $this->analysisResults = $cachedResults;
        }
    }

    /**
     * Parse the file once, store the resulting map on the file, and register every symbol it defines.
     *
     * A file whose contents are in the analysis cache is not parsed.
     */
    protected function find(string $contents, FileBase $file, ?ComposerPackage $package = null): void
    {
        // Already looked up, and missed, before the files were offered to the worker processes.
        $isKnownMiss = isset($this->missedCacheKeys[$file->getSourcePath()]);

        $cachedResult = is_null($this->analysisCache) || $isKnownMiss ? null : $this->analysisCache->get($contents);

        if (!is_null($cachedResult)) {
            [$codeMap, $errorMessage] = $cachedResult;
            if (is_null($codeMap)) {
                $this->logParseFailure($file, $errorMessage);
            }
        } else {
            $codeMap = $this->getAnalyzer()->analyze($contents, $file->getSourcePath());
            if (!is_null($this->analysisCache)) {
                $this->analysisCache->set($contents, $codeMap, $this->getAnalyzer()->getLastErrorMessage());
            }
        }

        $this->record($codeMap, $file, $package);
    }

    /**
     * Store the map on the file and register every symbol it defines. Null when the file could not be parsed.
     */
    protected function record(?FileCodeMap $codeMap, FileBase $file, ?ComposerPackage $package): void
    {
        if (is_null($codeMap)) {
            return;
        }

        if ($file instanceof File) {
            $file->setCodeMap($codeMap);
        }

        $this->recordDefinitions($codeMap, $file, $package);
    }

    /**
     * Register each namespace and every symbol declared within it, at any depth, e.g. a function declared inside
     * `if (!function_exists(...))`.
     */
    protected function recordDefinitions(FileCodeMap $codeMap, FileBase $file, ?ComposerPackage $package = null): void
    {
        foreach ($codeMap->getNamespaceNames() as $namespaceName) {
            $namespaceSymbol = $this->addDiscoveredNamespaceChange($namespaceName, $file, $package);

            $definitions = array_filter(
                $codeMap->getDefinitions(),
                fn(SymbolDefinition $definition): bool => $namespaceName === $definition->getNamespace()
                    && SymbolDefinition::TYPE_NAMESPACE !== $definition->getType()
            );

            // Grouped by type, in the order the symbols have always been registered.
            foreach ([
                SymbolDefinition::TYPE_CLASS,
                SymbolDefinition::TYPE_FUNCTION,
                SymbolDefinition::TYPE_CONSTANT,
                SymbolDefinition::TYPE_INTERFACE,
                SymbolDefinition::TYPE_TRAIT,
                SymbolDefinition::TYPE_ENUM,
            ] as $type) {
                foreach ($definitions as $definition) {
                    if ($type === $definition->getType()) {
                        $this->recordDefinition($definition, $file, $namespaceSymbol, $package);
                    }
                }
            }
        }
    }

    protected function recordDefinition(
        SymbolDefinition $definition,
        FileBase $file,
        NamespaceSymbol $namespaceSymbol,
        ?ComposerPackage $package = null
    ): void {
        $name = $definition->getName();

        switch ($definition->getType()) {
            case SymbolDefinition::TYPE_CLASS:
                $classSymbol = $this->addDiscoveredClassChange(
                    $name,
                    $definition->isAbstract(),
                    $file,
                    $definition->getExtends(),
                    $namespaceSymbol,
                    $definition->getInterfaces()
                );
                if ($classSymbol) {
                    $classSymbol->setDoRename($file->isDoPrefix());
                }
                return;

            case SymbolDefinition::TYPE_FUNCTION:
                if ($this->isBuiltInSymbol($name)) {
                    return;
                }
                $functionSymbol = $this->discoveredSymbols->getFunction($name);
                if (is_null($functionSymbol)) {
                    $functionSymbol = new FunctionSymbol($name, $file, $namespaceSymbol, $package);
                    $this->add($functionSymbol);
                }
                $functionSymbol->addSourceFile($file);
                $functionSymbol->setDoRename($file->isDoPrefix());
                return;

            case SymbolDefinition::TYPE_CONSTANT:
                // The name is as written in the source; {@see ConstantSymbol} prefixes the namespace where unqualified.
                $constantSymbol = $this->discoveredSymbols->getConst($name);
                if (is_null($constantSymbol)) {
                    $constantSymbol = new ConstantSymbol($name, $file, $namespaceSymbol);
                    $this->add($constantSymbol, $file);
                }
                $constantSymbol->addSourceFile($file);
                $constantSymbol->setDoRename($file->isDoPrefix());
                return;

            case SymbolDefinition::TYPE_INTERFACE:
                $interfaceSymbol = $this->discoveredSymbols->getInterface($name);
                if (is_null($interfaceSymbol)) {
                    $interfaceSymbol = new InterfaceSymbol($name, $file, $namespaceSymbol);
                    $this->add($interfaceSymbol);
                }
                $interfaceSymbol->addSourceFile($file);
                $interfaceSymbol->setDoRename($file->isDoPrefix());
                return;

            case SymbolDefinition::TYPE_TRAIT:
                $traitSymbol = $this->discoveredSymbols->getTrait($name);
                if (is_null($traitSymbol)) {
                    $traitSymbol = new TraitSymbol($name, $file, $namespaceSymbol);
                    $this->add($traitSymbol);
                }
                $traitSymbol->addSourceFile($file);
                $traitSymbol->setDoRename($file->isDoPrefix());
                return;

            case SymbolDefinition::TYPE_ENUM:
                if ($this->isBuiltInSymbol($name)) {
                    return;
                }
                $enumSymbol = $this->discoveredSymbols->getEnum($name);
                if (is_null($enumSymbol)) {
                    $enumSymbol = new EnumSymbol(
                        $name,
                        $file,
                        $namespaceSymbol,
                        $definition->getBackingType(),
                        $definition->getInterfaces()
                    );
                    $this->add($enumSymbol, $file);
                }
                $enumSymbol->addSourceFile($file);
                $enumSymbol->setDoRename($file->isDoPrefix());
                return;

            default:
                return;
        }
    }

    /**
     * @param string $fqdnClassname
     * @param bool $isAbstract
     * @param FileBase $file
     * @param ?string $extends
     * @param NamespaceSymbol|null $namespace
     * @param string[] $interfaces
     */
    protected function addDiscoveredClassChange(
        string $fqdnClassname,
        bool $isAbstract,
        FileBase $file,
        ?string $extends,
        ?NamespaceSymbol $namespace,
        array $interfaces
    ): ?ClassSymbol {
        // TODO: This should be included but marked not to prefix.
        if ($this->isBuiltInSymbol($fqdnClassname)) {
            $this->logger->debug('Skipping built-in symbol {symbolName}, possible a polyfill.', [
                'symbolName' => $fqdnClassname,
            ]);
            return null;
        }

        $classSymbol = $this->discoveredSymbols->getClass($fqdnClassname);
        if (is_null($classSymbol)) {
            $classSymbol = new ClassSymbol($fqdnClassname, $file, $namespace, $isAbstract, $extends, $interfaces);
            $this->add($classSymbol, $file);
        }
        $classSymbol->addSourceFile($file);
        if ($file instanceof FileWithDependency) {
            $file->addDiscoveredSymbol($classSymbol);
        }
        return $classSymbol;
    }

    protected function addDiscoveredNamespaceChange(string $fqdnNamespace, FileBase $file, ?ComposerPackage $package = null): NamespaceSymbol
    {
        $namespaceObj = $this->discoveredSymbols->getNamespace($fqdnNamespace);
        if (is_null($namespaceObj)) {
            $namespaceObj = new NamespaceSymbol($fqdnNamespace, $file);
            $this->add($namespaceObj);
        }
        $namespaceObj->addSourceFile($file);
        if ($file instanceof FileWithDependency) {
            $file->addDiscoveredSymbol($namespaceObj);
        }
        return $namespaceObj;
    }

    /**
     * Get a list of PHP built-in classes etc. so they are not prefixed.
     *
     * Polyfilled classes were being prefixed, but the polyfills are only active when the PHP version is below X,
     * so calls to those prefixed polyfilled classnames would fail on newer PHP versions.
     *
     * NB: This list is not exhaustive. Any unloaded PHP extensions are not included.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/79
     *
     * ```
     * array_filter(
     *   get_declared_classes(),
     *   function(string $className): bool {
     *     $reflector = new \ReflectionClass($className);
     *     return empty($reflector->getFileName());
     *   }
     * );
     * ```
     *
     * @return string[]
     */
    protected function getBuiltIns(): array
    {
        if (empty($this->builtIns)) {
            $this->loadBuiltIns();
        }

        return $this->builtIns;
    }

    /**
     * Load the file containing the built-in PHP classes etc. and flatten to a single array of strings and store.
     */
    protected function loadBuiltIns(): void
    {
        $builtins = include __DIR__ . '/FileSymbol/builtinsymbols.php';

        $flatArray = array();
        array_walk_recursive(
            $builtins,
            function ($array) use (&$flatArray) {
                if (is_array($array)) {
                    $flatArray = array_merge($flatArray, array_values($array));
                } else {
                    $flatArray[] = $array;
                }
            }
        );

        $this->builtIns = $flatArray;
        $this->builtInsLookup = array_fill_keys($this->builtIns, true);
    }

    protected function isBuiltInSymbol(string $symbolName): bool
    {
        if (empty($this->builtInsLookup)) {
            $this->loadBuiltIns();
        }

        return isset($this->builtInsLookup[$symbolName]);
    }

    protected function getAnalyzer(): PhpFileAnalyzer
    {
        return $this->analyzer ??= new PhpFileAnalyzer($this->logger);
    }
}
