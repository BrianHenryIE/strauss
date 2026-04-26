<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\ProjectComposerPackage;
use BrianHenryIE\Strauss\Config\PrefixerConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespacedSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Exception;
use League\Flysystem\FilesystemException;
use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Prefixer
{
    use LoggerAwareTrait;

    protected PrefixerConfigInterface $config;

    protected FileSystem $filesystem;

    /**
     * array<$filePath, $package> or null if the file is not from a dependency (i.e. a project file).
     *
     * @var array<string, ?ComposerPackage>
     */
    protected array $changedFiles = array();

    public function __construct(
        PrefixerConfigInterface $config,
        FileSystem              $filesystem,
        ?LoggerInterface        $logger = null
    ) {
        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->logger = $logger ?? new NullLogger();
    }

    // Don't replace a classname if there's an import for a class with the same name.
    // but do replace \Classname always

    /**
     * @param DiscoveredSymbols $discoveredSymbols
     * ///param array<string,array{dependency:ComposerPackage,sourceAbsoluteFilepath:string,targetRelativeFilepath:string}> $phpFileArrays
     * @param array<string, FileBase> $files
     *
     * @throws FilesystemException
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
            $this->logger->info("Updated contents of file: {targetAbsolutePath}", [
                'targetAbsolutePath' => $file->getTargetAbsolutePath()
            ]);
        } else {
            $this->logger->debug("No changes to file: {targetAbsolutePath}", [
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
                $this->logger->debug("is_dir() / nothing to do : {relativeFilePath}", [
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
                $this->logger->warning("Expected file does not exist: {relativeFilePath}", [
                    'relativeFilePath' => $relativeFilePath
                ]);
                continue;
            }

            $this->logger->debug("Updating contents of file (project): {fileAbsolutePath}", [
                'fileAbsolutePath' => $fileAbsolutePath,
            ]);

            // Throws an exception, but unlikely to happen.
            $contents = $this->filesystem->read($fileAbsolutePath);

            $updatedContents = $this->replaceInString($discoveredSymbols, $contents, $file);

            if ($updatedContents !== $contents) {
                $this->changedFiles[$fileAbsolutePath] = null;
                $this->filesystem->write($fileAbsolutePath, $updatedContents);
                $this->logger->info('Updated contents of file: ' . $relativeFilePath);
            } else {
                $this->logger->debug('No changes to file: ' . $relativeFilePath);
            }
        }
    }

    /**
     * @param DiscoveredSymbols $discoveredSymbols
     * @param string $contents
     *
     * @throws Exception
     */
    public function replaceInString(DiscoveredSymbols $discoveredSymbols, string $contents, ?FileBase $file = null): string
    {
        $fileAbsolutePath = is_null($file) ? null : $file->getTargetAbsolutePath();

        $namespacesChanges = $discoveredSymbols->getDiscoveredNamespaces()->getToRename();
        $constants = $discoveredSymbols->getDiscoveredConstants($this->config->getConstantsPrefix())->getToRename();
        $functionsToRename = $discoveredSymbols->getDiscoveredFunctions()->getToRename();

        // This is maybe deprecated since regex has been replaced by php-parser.
        $contents = $this->prepareRelativeNamespaces($contents, $discoveredSymbols->getDiscoveredNamespaces());

        // Prepend <?php if absent so php-parser treats the content as PHP code rather
        // than inline HTML. The offset is subtracted from all collected positions below.
        $phpOpenerLen = 0;
        $parseContent = $contents;
        if (stripos(ltrim($contents), '<?') !== 0) {
            $phpOpenerLen = strlen("<?php\n");
            $parseContent = "<?php\n" . $contents;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $errorHandler = new \PhpParser\ErrorHandler\Collecting();
        $ast = null;

        $positions = [];

        try {
            $this->logger->debug("Parsing {filePath} AST", [
                'filePath' => $fileAbsolutePath ?? 'file',
            ]);
            $ast = $parser->parse($parseContent);
//                $ast = $parser->parse($parseContent, $errorHandler);
        } catch (\Exception $e) {
            // This happens in template files, E.g `x.blade.php`.
            $this->logger->warning("Skipping Prefixing in {filePath} due to parse error: " . $e->getMessage(), [
                'filePath' => $fileAbsolutePath ?? 'file',
            ]);
            return $contents;
        }

        if (is_null($ast)) {
            $this->logger->warning("AST parse failed for {filePath}, returning.", [
                'filePath' => $fileAbsolutePath ?? 'file',
            ]);
            return $contents;
        }

        $positions = array_merge(
            $positions,
            $this->replaceUseStatementsForNamespacedClasses($ast, $discoveredSymbols),
            $this->replaceNamespaces($ast, $discoveredSymbols, $file),
            $this->findFunctionPositionsInAst($ast, $functionsToRename),
            $this->findDocCommentPositionsInAst($ast, $discoveredSymbols),
            $this->replaceConstFetchNamespaces($discoveredSymbols, $ast),
            $this->findGlobalSymbolsPositionsInAst($ast, $discoveredSymbols),
        );

        // Adjust positions to be relative to the original $contents (before any <?php prepend).
        if ($phpOpenerLen > 0) {
            $positions = array_values(array_filter(
                array_map(function ($pos) use ($phpOpenerLen) {
                    $pos['start'] -= $phpOpenerLen;
                    $pos['end'] -= $phpOpenerLen;
                    return $pos;
                }, $positions),
                fn($pos) => $pos['start'] >= 0
            ));
        }

        usort($positions, fn($a, $b) => $b['start'] <=> $a['start']);

        $removeDuplicatePositions = [];
        foreach ($positions as $position) {
//            if(isset($removeDuplicatePositions[$position['start']])){
//            }
            $removeDuplicatePositions[$position['start']] = $position;
        }
        $positions = $removeDuplicatePositions;

        foreach ($positions as $pos) {
            $contents = substr_replace($contents, $pos['replacement'], $pos['start'], $pos['end'] - $pos['start']);
        }

        // TODO: Use AST.
        if (!is_null($this->config->getConstantsPrefix())) {
            $contents = $this->replaceConstants($contents, $constants, $this->config->getConstantsPrefix());
        }

        // The following are for replacing symbols inside strings.
        // TODO: When functions and constants are implemented via AST, their respective string replacement part will need to be added here.

        // Only search for symbols that might possibly be in this file.
        // TODO: During the initial AST parse which now find symbols defined in a file, also record the symbols used in the file
        if ($file instanceof FileWithDependency && !($file->getDependency() instanceof ProjectComposerPackage)) {
            $discoveredSymbols = $file->getDependency()->getDiscoveredSymbolsDeep();
        }

        $discoveredSymbolsCount = count($discoveredSymbols->toArray());
        $this->logger->debug(sprintf(
            'Searching in {filename} for {count} symbol%s as string',
            $discoveredSymbolsCount === 0 ? '' : 's'
        ), [
            'filename' => basename($fileAbsolutePath),
            'count' => $discoveredSymbolsCount,
        ]);

        // TODO: filter to only namespaces of more than a single depth.
        /** @var NamespaceSymbol $namespaceSymbol */
        foreach ($discoveredSymbols->getNamespaces()->getToRename() as $namespaceSymbol) {
//            $this->logger->debug('Searching in {filename} for {type}: {name}', [
//                'filename' => basename($fileAbsolutePath),
//                'type' => array_reverse(explode('\\', get_class($namespaceSymbol)))[0],
//                'name' => $namespaceSymbol->getOriginalLocalName()
//            ]);

            $contents = $this->replaceSingleClassnameInString($contents, $namespaceSymbol);
        }

        /** @var ClassSymbol $classSymbol */
        foreach ($discoveredSymbols->getNamespacedSymbols()->getToRename() as $classSymbol) {
//            $this->logger->debug('Searching in {filename} for {type}: {name}', [
//                'filename' => basename($fileAbsolutePath),
//                'type' => array_reverse(explode('\\', basename(get_class($classSymbol))))[0],
//                'name' => $classSymbol->getOriginalLocalName(),
//            ]);

            $contents = $this->replaceSingleClassnameInString($contents, $classSymbol);
        }

        return $contents;
    }

    protected function replaceConstFetchNamespaces(DiscoveredSymbols $symbols, array $ast): array
    {
        $namespaceSymbols = $symbols->getDiscoveredNamespaces();
        $namespaceSymbolsArray = $namespaceSymbols->toArray();
        if (empty($namespaceSymbolsArray)) {
            return [];
        }

        $nodeFinder = new NodeFinder();
        $positions = [];

        /** @var ConstFetch[] $constFetches */
        $constFetches = $nodeFinder->find($ast, function (Node $node) {
            return $node instanceof ConstFetch
                && $node->name instanceof FullyQualified;
        });

        foreach ($constFetches as $fetch) {
            $full = $fetch->name->toString();
            $parts = explode('\\', $full);
            $namespace = $parts[0] ?? null;

            if ($namespace && isset($namespaceSymbolsArray[$namespace])) {
                $replacementNamespace = $namespaceSymbolsArray[$namespace]->getLocalReplacement();
                $parts[0] = $replacementNamespace;
                $newName = '\\' . implode('\\', $parts);

                $positions[] = [
                    'start' => $fetch->name->getStartFilePos(),
                    'end' => $fetch->name->getEndFilePos() + 1,
                    'replacement' => $newName,
                ];
            }
        }

        return $positions;
    }

    /**
     * Replace class/interface/trait `use` statements driven by registered ClassSymbols.
     *
     * A namespace is "active" when at least one ClassSymbol is registered within it.
     * For exact-match ClassSymbols the symbol's own replacement is used; for other classes
     * in the namespace, namespace-prefix replacement is applied.
     * Namespaces with no registered ClassSymbol are left alone.
     */
    protected function replaceUseStatementsForNamespacedClasses(array $ast, DiscoveredSymbols $discoveredSymbols): array
    {
        $activeNamespaces = [];
        /** @var NamespacedSymbol $symbol */
        foreach ($discoveredSymbols->getNamespacedSymbols()->getToRename()->notGlobal()->toArray() as $symbol) {
            $ns = $symbol->getNamespace();
            $original = rtrim($ns->getOriginalSymbol(), '\\');
            $replacement = rtrim($ns->getReplacementFqdnName(), '\\');
            $activeNamespaces[$original] = $replacement;
        }

        if (empty($activeNamespaces)) {
            return [];
        }

        uksort($activeNamespaces, fn($a, $b) => strlen($b) - strlen($a));

        $nodeFinder = new NodeFinder();
        $positions = [];

        $namespacedSymbols = $discoveredSymbols->getNamespacedSymbols()->getToRename()->notGlobal();
        $useItems = $nodeFinder->findInstanceOf($ast, UseItem::class);
        foreach ($useItems as $item) {
            $nameStr = $item->name->toString();
            // Full match.
            if ($namespacedSymbols->get($nameStr)) {
                $positions[] = [
                    'start'       => $item->name->getStartFilePos(),
                    'end'         => $item->name->getEndFilePos() + 1,
                    'replacement' => $namespacedSymbols->get($nameStr)->getReplacementFqdnName(),
                ];
            } else { // Partial match (group)
                foreach ($activeNamespaces as $original => $replacement) {
                    if (str_starts_with($nameStr, $original . '\\')) {
//                if ($nameStr === $original) {
                        /** @var ?DiscoveredSymbol $classSymbol */
                        $classSymbol = $namespacedSymbols->get($nameStr);
                        if ($classSymbol && $classSymbol->isDoRename()) {
                            $nsReplacement = rtrim($classSymbol->getNamespace()->getReplacementFqdnName(), '\\');
                            $newName       = $nsReplacement . '\\' . $classSymbol->getLocalReplacement();
//                        $newName = $nsReplacement . $classSymbol->getLocalReplacement();
                        } else {
                            $newName = $replacement . substr($nameStr, strlen($original));
                        }

                        $positions[] = [
                            'start'       => $item->name->getStartFilePos(),
                            'end'         => $item->name->getEndFilePos() + 1,
                            'replacement' => $newName,
                        ];
                    }
                }
            }
        }

        return $positions;
    }

    protected function replaceNamespaces(array $ast, DiscoveredSymbols $discoveredSymbols, FileBase $file): array
    {
        $namespaces = $discoveredSymbols->getNamespaces();
        $namespacedChanges = $discoveredSymbols->getNamespacedSymbols()->notGlobal();
        if (empty($namespaces->getToRename())) {
            return [];
        }

        /** @var NamespaceSymbol[] $symbolMap indexed by exact original symbol (no trailing \) */
        $symbolMap = [];
//        foreach ($namespaceChanges->getNamespaces()->notGlobal() as $symbol) {
        foreach ($namespaces->getToRename() as $symbol) {
            if (isset($symbolMap[rtrim($symbol->getOriginalSymbol(), '\\')])) {
                throw new Exception('losing data');
            }
            $symbolMap[rtrim($symbol->getOriginalSymbol(), '\\')] = $symbol;
        }
        uksort($symbolMap, fn($a, $b) => strlen($b) - strlen($a));

        $nodeFinder = new NodeFinder();
        $positions = [];
        $handled = [];

        /**
         * Prefix lookup for qualified names like Aws\SomeClass or Aws\boolean_value():
         * walks from the longest prefix down to length-1, doing exact key lookups.
         * Returns ['symbol' => DiscoveredSymbol, 'suffix' => 'remaining\parts'] or null.
         *
         * @param string[] $parts
         */
        $findPrefixSymbol = function (array $parts) use ($symbolMap, $discoveredSymbols): ?array {
            for ($len = count($parts) - 1; $len >= 1; $len--) {
                $prefix = implode('\\', array_slice($parts, 0, $len));

                $discoveredNamespace = $discoveredSymbols->getNamespace($prefix);
                if (isset($symbolMap[$prefix])) {
//                if ($discoveredNamespace) {
                    return [
                        'symbol' => $symbolMap[$prefix],
                        'suffix' => implode('\\', array_slice($parts, $len)),
                    ];
                }
            }
            return null;
        };

        // A: namespace declarations — keep relative (no leading \)
        foreach ($nodeFinder->findInstanceOf($ast, Namespace_::class) as $ns) {
            if ($ns->name === null) {
                continue;
            }
            if (!$file->isDoPrefix()) {
                $handled[$ns->name->getStartFilePos()] = true;
                continue;
            }
            $nameStr = $ns->name->toString();

            if (isset($symbolMap[$nameStr])) {
                $namespaceSymbol = $symbolMap[$nameStr];
                $positions[] = [
                    'start' => $ns->name->getStartFilePos(),
                    'end' => $ns->name->getEndFilePos() + 1,
                    'replacement' => $namespaceSymbol->getReplacementFqdnName(),
                ];
                $handled[$ns->name->getStartFilePos()] = true;
            }

            if ($symbol = $namespacedChanges->get($nameStr)) {
                $replacement = $symbol->getReplacementFqdnName();
            } elseif ($match = $findPrefixSymbol($ns->name->getParts())) {
                $replacement = rtrim($match['symbol']->getReplacementFqdnName(), '\\') . '\\' . $match['suffix'];
            } else {
                continue;
            }
            $positions[] = [
                'start' => $ns->name->getStartFilePos(),
                'end' => $ns->name->getEndFilePos() + 1,
                'replacement' => $replacement,
            ];
            $handled[$ns->name->getStartFilePos()] = true;
        }

        // B: use items.
        // Class/interface/trait use items are always marked as handled to prevent section D from
        // prepending '\'; their replacement is produced by replaceUseStatementsForNamespacedClasses.
        // Function and constant use items keep namespace-prefix replacement here.
        foreach ($nodeFinder->findInstanceOf($ast, Use_::class) as $useStmt) {
            foreach ($useStmt->uses as $item) {
                $nameStr = $item->name->toString();
                // Always mark use item names as handled so section D never adds a spurious '\' prefix.
                $handled[$item->name->getStartFilePos()] = true;
                if ($useStmt->type !== Use_::TYPE_NORMAL) {
                    // TYPE_FUNCTION / TYPE_CONSTANT: replace directly here.
                    if ($symbol = $discoveredSymbols->get($nameStr)) {
                        $replacement = $symbol->getReplacementFqdnName();
                    } elseif ($match = $findPrefixSymbol($item->name->getParts())) {
                        // groups
                        $replacement = rtrim($match['symbol']->getReplacementFqdnName(), '\\') . '\\' . $match['suffix'];
                    } else {
                        continue;
                    }
                    $positions[] = [
                        'start' => $item->name->getStartFilePos(),
                        'end' => $item->name->getEndFilePos() + 1,
                        'replacement' => $replacement,
                    ];
                    $handled[$item->getStartFilePos()] = true;
                } elseif (isset($symbolMap[$nameStr])) {
                    // TYPE_NORMAL with an exact namespace match: replaceUseStatementsForNamespacedClasses
                    // only handles class/trait/interface/enum symbols, so handle pure namespace use items here.
                    $positions[] = [
                        'start' => $item->name->getStartFilePos(),
                        'end' => $item->name->getEndFilePos() + 1,
                        'replacement' => $symbolMap[$nameStr]->getReplacementFqdnName(),
                    ];
                }
            }
        }
        // It would be necessary to split `use My\Namespace\{Class1, Class2};` into individual lines if one of
        // those classes is excluded and one should be updated.
        foreach ($nodeFinder->findInstanceOf($ast, GroupUse::class) as $groupUse) {
            if ($groupUse->prefix === null) {
                continue;
            }
            $nameStr = $groupUse->prefix->toString();
            if ($symbol = $discoveredSymbols->get($nameStr)) {
                $replacement = $symbol->getReplacementFqdnName();
            } elseif ($match = $findPrefixSymbol($groupUse->prefix->getParts())) {
                $replacement = rtrim($match['symbol']->getReplacementFqdnName(), '\\') . '\\' . $match['suffix'];
            } else {
                continue;
            }
            $positions[] = [
                'start' => $groupUse->prefix->getStartFilePos(),
                'end' => $groupUse->prefix->getEndFilePos() + 1,
                'replacement' => $replacement,
            ];
            $handled[$groupUse->prefix->getStartFilePos()] = true;
        }

        // C: fully-qualified Name nodes — retain leading \
        foreach ($nodeFinder->findInstanceOf($ast, FullyQualified::class) as $name) {
            if (isset($handled[$name->getStartFilePos()])) {
                continue;
            }
            if ($symbol = $namespacedChanges->get($name->toString())) {
                $replacement = $symbol->getReplacementFqdnName();
            } elseif ($match = $findPrefixSymbol($name->getParts())) {
                $replacement = rtrim($match['symbol']->getReplacementFqdnName(), '\\') . '\\' . $match['suffix'];
            } else {
                continue;
            }
            $positions[] = [
                'start' => $name->getStartFilePos(),
                'end' => $name->getEndFilePos() + 1,
                'replacement' => '\\' . $replacement,
            ];
            $handled[$name->getStartFilePos()] = true;
        }

        // D: relative qualified Name nodes (e.g. Aws\boolean_value, Aws\SomeClass) — promote to FQ.
        // Uses part-by-part prefix lookup so only full namespace-segment boundaries are matched.
        foreach ($nodeFinder->find($ast, function (Node $node) {
            return $node instanceof Name
                && !($node instanceof FullyQualified)
                && count($node->getParts()) >= 2;
        }) as $name) {
            // This needs to be available to other functions.
            if (isset($handled[$name->getStartFilePos()])) {
                continue;
            }

            if (isset($symbolMap[$name->toString()])) {
                $namespaceSymbol = $symbolMap[$name->toString()];
                $positions[] = [
                    'start' => $name->getStartFilePos(),
                    'end' => $name->getEndFilePos() + 1,
                    'replacement' => $namespaceSymbol->getReplacementFqdnName(),
                ];
                continue;
            }

            $match = $findPrefixSymbol($name->getParts());
            if (!$match) {
                continue;
            }
            $namespaceSymbol = $match['symbol'];

            if (!$file->isDoPrefix() && $file->getDiscoveredSymbols()->has($namespaceSymbol)) {
                continue;
            }

            $positions[] = [
                'start' => $name->getStartFilePos(),
                'end' => $name->getEndFilePos() + 1,
                'replacement' => '\\' . $namespaceSymbol->getReplacementFqdnName() . '\\' . $match['suffix'],
//                'replacement' => '\\' . $match['symbol']->getReplacementFqdnName() . '\\' . $match['suffix'],
            ];
        }

        return $positions;
    }

    protected function findGlobalSymbolsPositionsInComment(Comment $comment, DiscoveredSymbols $globalSymbols): array
    {
        $positions = [];
        foreach ($globalSymbols->getGlobalClassesInterfacesTraitsToRename() as $discoveredSymbol) {
            $positions = array_merge($positions, $this->findGlobalSymbolPositionInComment($comment, $discoveredSymbol));
        }
        return $positions;
    }

    protected function findGlobalSymbolPositionInComment(Comment $comment, DiscoveredSymbol $globalSymbol): array
    {
        $positions = [];

        $searchStr = '\\' . $globalSymbol->getOriginalSymbol();
        $searchLen = strlen($searchStr);

        $commentText = $comment->getText();
        $startFilePos = $comment->getStartFilePos();
        $offset = 0;
        while (($pos = strpos($commentText, $searchStr, $offset)) !== false) {
            $nextPos = $pos + $searchLen;
            if ($nextPos >= strlen($commentText)
                || !preg_match('/[a-zA-Z0-9_\x7f-\xff\\\\]/', $commentText[$nextPos])
            ) {
                $positions[] = [
                    'start' => $startFilePos + $pos,
                    'end' => $startFilePos + $nextPos,
                    'replacement' => '\\' . $globalSymbol->getLocalReplacement(),
                ];
            }
            $offset = $pos + 1;
        }

        return $positions;
    }

    /**
     * TODO: filter the changes in a file to something like `$symbol->getPackages()[0]->getFlatDependencyTree()`.
     * The file should not be using symbols that are not defined in their required dependencies.
     *
     * @param string $contents
     * @param DiscoveredSymbol $symbol
     *
     * @return string
     */
    protected function replaceSingleClassnameInString(string $contents, DiscoveredSymbol $symbol, bool $requireSurroundingQuotes = true): string
    {
        $alsoSearchForVariableClassname = false;
        $alsoSearchForStaticProperty = false;

        if ($symbol instanceof NamespacedSymbol && $symbol->getNamespace()->isGlobal()) {
            $replacementSymbolString = $symbol->getLocalReplacement();
            $originalSymbolString    = $symbol->getOriginalSymbolStripPrefix($this->config->getClassmapPrefix());
        } elseif ($symbol instanceof NamespaceSymbol) {
            if ($symbol->isGlobal()) {
                return $contents;
            }
            $originalSymbolString = $symbol->getOriginalSymbol();
            $replacementSymbolString = $symbol->getReplacementFqdnName();

            // E.g. `My\Namespace\$var` is used in some libraries.
            $alsoSearchForVariableClassname = true;
        } else {
            $originalSymbolString = $symbol->getOriginalFqdnName();
            $replacementSymbolString = $symbol->getFqdnReplacement();
            $alsoSearchForStaticProperty = true;
        }

        /**
         * Replace classnames in strings, e.g. `is_a( $recurrence, 'CronExpression' )`.
         *
         * `[^a-zA-Z0-9_\x7f-\xff\\\\]+` is anything but classname valid characters.
         *
         * TODO: Run this without the classname characters, log everytime a replacement is made across all test cases, add those to the test assertions, ensure this is always correct.
         */
        $pattern =    '/
(
                            [^a-zA-Z0-9_\x7f-\xff\\\\]
                             ' . ($requireSurroundingQuotes ? '[\'"]' : '' ) .'
                            [\\\\]{0,2}
)
                        ('
                            . str_replace('\\', '[\\\\]{1,2}', $originalSymbolString) .
                        ')(
                        '
                      . ( $alsoSearchForVariableClassname ? '([\\\\]{1,2}\$[a-zA-Z0-9_\x7f-\xff]*)?' : '' ) .
                      ( $alsoSearchForStaticProperty ? '(:{2}\$[a-zA-Z0-9_\x7f-\xff]*)?' : '' ) .
                      '
                            ' . ($requireSurroundingQuotes ? '[\'"]' : '' ) .'
                            [^a-zA-Z0-9_\x7f-\xff\\\\]
)
                        /Ux';       // U: Non-greedy matching, x: ignore whitespace in pattern.

        /**
         * If $alsoSearchForVariableClassname the number of elements in the array is more
         *
         * @param array<array<string>> $capture
         *
         * @return string
         */
        $replacement = function (array $capture) use ($originalSymbolString, $replacementSymbolString, $alsoSearchForVariableClassname) {

            if ($capture[2] === $originalSymbolString) {
                $capture[2] = $replacementSymbolString;
            }

            if ($capture[2] === str_replace('\\', '\\\\', $originalSymbolString)) {
                $capture[2] = str_replace('\\', '\\\\', $replacementSymbolString);
            }

            unset($capture[0]);
            unset($capture[4]);

            return implode('', $capture);
        };

        $contents = preg_replace_callback($pattern, $replacement, $contents);

        return $contents;
    }

    /**
     * In a namespace:
     * * use \Classname;
     * * new \Classname()
     *
     * In a global namespace:
     * * new Classname()
     *
     * @param string $contents
     * @param string $originalClassname
     * @param string $classnamePrefix
     */
    public function findGlobalSymbolsPositionsInAst(array $ast, DiscoveredSymbols $discoveredSymbols): array
    {
        $globalClassesInterfacesTraitsToRename = $discoveredSymbols->getGlobalClassesInterfacesTraits()->getToRename();

        if (empty($globalClassesInterfacesTraitsToRename)) {
            return [];
        }

        $nodeFinder = new NodeFinder();
        $positions = [];

        // Replace \Classname (fully qualified) references in any namespace context.
        $fqNodes = $nodeFinder->find($ast, function (Node $node) use ($discoveredSymbols, &$positions) {
            if ($node->getAttribute('comments')) {
                // TODO. This is recording comments repeatedly. Duplicates are later removed, but it'd be better to just not add them.
                /** @var Doc $comment */
                foreach ($node->getAttribute('comments') as $comment) {
                    $positions = array_merge(
                        $positions,
                        $this->findGlobalSymbolsPositionsInComment($comment, $discoveredSymbols)
                    );
                }
            }
            if (!( $node instanceof FullyQualified )) {
                return false;
            }
            return $this->hasGlobalSymbolForNode($node, $discoveredSymbols);
        });

        foreach ($fqNodes as $node) {
            $positions[] = [
                'start' => $node->getStartFilePos(),
                'end' => $node->getEndFilePos() + 1,
                'replacement' => '\\' . $discoveredSymbols->getNamespacedSymbols()->get($node->toString())->getReplacementFqdnName(),
            ];
        }

        // In named namespaces, `use Classname;` must become `use PrefixedClassname as Classname;`
        // so that unqualified references within the namespace continue to resolve correctly.
        $namedNamespaces = array_filter(
            $nodeFinder->findInstanceOf($ast, Namespace_::class),
            fn($ns) => $ns->name !== null
        );
        foreach ($namedNamespaces as $nsStmt) {
            $useItems = $nodeFinder->findInstanceOf($nsStmt->stmts ?? [], UseItem::class);
            foreach ($useItems as $useItem) {
                $fqdn_name = $useItem->name->toString();
                $discoveredSymbol = $globalClassesInterfacesTraitsToRename->get($fqdn_name);
                if (!($useItem->name instanceof FullyQualified) && $discoveredSymbol && $discoveredSymbol->isDoRename()) {
                        $replacementClassname = $discoveredSymbol->getLocalReplacement();
                        $useClassname = array_reverse(explode('\\', $fqdn_name))[0];

                        $replacementString = $discoveredSymbol->getLocalReplacement();
                    if ($replacementClassname !== $useClassname && !$useItem->alias) {
                        $replacementString .= ' as ' . $useClassname;
                    }

                        $positions[] = [
                            'start' => $useItem->name->getStartFilePos(),
                            'end' => $useItem->name->getEndFilePos() + 1,
                            'replacement' => $replacementString,
                        ];
                }
            }
        }

        // In global namespace context (either implicit, or explicit `namespace {}`), replace
        // unqualified class name references and class/interface/trait/enum declarations.
        $globalStmts = [];
        foreach ($ast as $node) {
            if ($node instanceof Namespace_) {
                if ($node->name === null) {
                    $globalStmts = array_merge($globalStmts, $node->stmts ?? []);
                }
            } else {
                $globalStmts[] = $node;
            }
        }

        $classLike = $nodeFinder->find($globalStmts, function (Node $node) use ($globalClassesInterfacesTraitsToRename) {
            return ($node instanceof Class_
                || $node instanceof Interface_
                || $node instanceof Trait_
                || $node instanceof Enum_)
                && isset($node->name)
                && $node->name instanceof Identifier
                && (
                       $globalClassesInterfacesTraitsToRename->getClass($node->name->name)
                       || $globalClassesInterfacesTraitsToRename->getInterface($node->name->name)
                       || $globalClassesInterfacesTraitsToRename->getTrait($node->name->name)
                   );
        });
        foreach ($classLike as $node) {
            $replacement = $this->getReplacementStringForNode($node, $globalClassesInterfacesTraitsToRename);
            $positions[] = [
                'start' => $node->name->getStartFilePos(),
                'end' => $node->name->getEndFilePos() + 1,
                'replacement' => $replacement
            ];
        }

        $unqualifiedNameNodes = $nodeFinder->find($globalStmts, function (Node $node) use ($globalClassesInterfacesTraitsToRename) {
            return $node instanceof Name
                && !($node instanceof FullyQualified)
                   && $this->hasGlobalSymbolForNode($node, $globalClassesInterfacesTraitsToRename);
        });
        foreach ($unqualifiedNameNodes as $node) {
            $replacement = $globalClassesInterfacesTraitsToRename->get($node->name)->getReplacementFqdnName();
            $positions[] = [
                'start' => $node->getStartFilePos(),
                'end' => $node->getEndFilePos() + 1,
                'replacement' => $replacement,
//                'replacement' => $this->getReplacementStringForNode($node, $globalClassesInterfacesTraitsToRename)
            ];
        }

        return $positions;
    }

    protected function hasGlobalSymbolForNode(Node $node, DiscoveredSymbols $discoveredSymbols): bool
    {
        return (bool) $this->getGlobalSymbolForNode($node, $discoveredSymbols);
    }

    /**
     * Try to get the specific class/interface/trait symbol by type and name.
     * Failing that, return whichever of class/interface/trait is found first by name, or null.
     *
     * There should just be one of any global name: `use MyABC;` (a class) is indistinguishable from `use MyABC;` (an interface).
     *
     * @param Node\Stmt $node
     * @param DiscoveredSymbols $discoveredSymbols
     *
     * @return DiscoveredSymbol|null
     */
    protected function getGlobalSymbolForNode(Node $node, DiscoveredSymbols $discoveredSymbols): ?DiscoveredSymbol
    {
        if ($node instanceof Class_) {
            return $discoveredSymbols->getClass($node->name->toString());
        }
        if ($node instanceof Interface_) {
            return $discoveredSymbols->getInterface($node->name->toString());
        }
        if ($node instanceof Trait_) {
            return $discoveredSymbols->getTrait($node->name->toString());
        }
        if ($node instanceof Enum_) {
            return $discoveredSymbols->getEnum($node->name->toString());
        }
        switch (true) {
            case $node->name instanceof Name:
                $nodeNameString = $node->name->toString();
            case $node instanceof Name:
                $nodeNameString = $nodeNameString ?? $node->toString();
                return $discoveredSymbols->getClass($nodeNameString)
                       ?? $discoveredSymbols->getInterface($nodeNameString)
                          ?? $discoveredSymbols->getTrait($nodeNameString);
            default:
                // TODO: enums.
                return null;
        }
    }

    protected function getReplacementStringForNode(Node $node, DiscoveredSymbols $discoveredSymbols)
    {
        $globalSymbol = $this->getGlobalSymbolForNode($node, $discoveredSymbols);
        if ($globalSymbol) {
            return $globalSymbol->getLocalReplacement();
        }
        return $node->toString();
    }

    /**
     * TODO: This should be split and brought to FileScanner.
     *
     * @param string $contents
     * @param string[] $originalConstants
     * @param string $prefix
     */
    protected function replaceConstants(string $contents, DiscoveredSymbols $originalConstants, string $prefix): string
    {

        foreach ($originalConstants as $constant) {
            $contents = $this->replaceConstant($contents, $constant, $prefix . $constant);
        }

        return $contents;
    }

    protected function replaceConstant(string $contents, string $originalConstant, string $replacementConstant): string
    {
        return str_replace($originalConstant, $replacementConstant, $contents);
    }

    /**
     * Look for declared functions, function calls, and built-in functions that accept a function as their parameter.
     *
     * @see Function_
     * @see FuncCall
     */
    protected function findFunctionPositionsInAst(array $ast, DiscoveredSymbols $discoveredSymbols): array
    {
        $positions = [];

        $nodeFinder = new NodeFinder();

        // Function declarations (global only)
        $functionDefs = $nodeFinder->findInstanceOf($ast, Function_::class);
        foreach ($functionDefs as $func) {
            $functionSymbol = $discoveredSymbols->getFunction($func->name->name);
            if ($functionSymbol && $functionSymbol->isDoRename()) {
                $positions[] = [
                    'start'       => $func->name->getStartFilePos(),
                    'end'         => $func->name->getEndFilePos() + 1,
                    'replacement' => $functionSymbol->getFqdnReplacement(),
                ];
            }
        }

        // Calls (global only)
        $functionCalls = $nodeFinder->findInstanceOf($ast, FuncCall::class);
        foreach ($functionCalls as $call) {
            if (! ( $call->name instanceof Name )) {
                // E.g. `$formatToPhpVersionId = static function (Bound $bound): int {}
                continue;
            }
            // If the function call is one that we found earlier.
            $functionSymbol = $discoveredSymbols->getFunction($call->name->toString());
            if ($functionSymbol) {
                if (str_contains($call->name->toString(), '\\')) {
                    $replacement = '\\' . $functionSymbol->getFqdnReplacement();
                } else {
                    $replacement = $functionSymbol->getLocalReplacement();
                }
                $positions[] = [
                    'start'       => $call->name->getStartFilePos(),
                    'end'         => $call->name->getEndFilePos() + 1,
                    'replacement' => $replacement,
                ];
                continue;
            }

            // If it is a build-in function that accepts a function name as its argument.
            $functionsUsingCallable = [
                'function_exists',
                'call_user_func',
                'call_user_func_array',
                'forward_static_call',
                'forward_static_call_array',
                'register_shutdown_function',
                'register_tick_function',
                'unregister_tick_function',
            ];

            if (in_array($call->name->toString(), $functionsUsingCallable)
                 && isset($call->args[0])
                 && $call->args[0] instanceof Arg
                 && $call->args[0]->value instanceof String_
                 && $discoveredSymbols->getFunction($call->args[0]->value->value)
            ) {
                $positions[] = [
                    'start'       => $call->args[0]->value->getStartFilePos() + 1, // do not change quotes
                    'end'         => $call->args[0]->value->getEndFilePos(),
                    'replacement' => $discoveredSymbols->getFunction($call->args[0]->value->value)->getFqdnReplacement(),
                ];
            }
        }

        return $positions;
    }
    protected function findDocCommentPositionsInAst(array $ast, DiscoveredSymbols $discoveredSymbols): array
    {
        $positions = [];

        $nodeFinder = new NodeFinder();

        $commentNodes = $nodeFinder->find($ast, function (Node $node) {
            return $node->getDocComment() !== null;
        });

        if (sizeof($commentNodes)===0) {
            return [];
        }

        $namespacedSymbols = $discoveredSymbols->getNamespacedSymbols()->getToRename();

        $this->logger->debug('Searching {number_of_comments} comments for {number_of_symbols} symbols', [
            'number_of_comments' => sizeof($commentNodes),
            'number_of_symbols' => count($namespacedSymbols),
        ]);

        // Doc comments: scan for \OriginalNamespace references in @param/@return/etc.
        foreach ($commentNodes as $node) {
            $doc = $node->getDocComment();
            $text = $doc->getText();
            /** @var NamespacedSymbol $symbol */
            foreach ($namespacedSymbols as $symbol) {
                $replacement = $symbol->getReplacementFqdnName();
//                $docSearchStr = '\\' . $symbol->getOriginalSymbol();
                $docSearchStr = $symbol->getOriginalSymbol();
                $docSearchLen = strlen($docSearchStr);
                // For global symbols (no \ in name), also treat \ as a boundary character
                // so \GlobalClass is left to findGlobalSymbolPositionInComment.
                $beforePattern = strpos($docSearchStr, '\\') === false
                    ? '/[a-zA-Z0-9_\x7f-\xff\\\\]/'
                    : '/[a-zA-Z0-9_\x7f-\xff]/';
                $offset = 0;
                while (($pos = strpos($text, $docSearchStr, $offset)) !== false) {
                    $after = $pos + $docSearchLen;
                    $beforeOk = $pos === 0 || !preg_match($beforePattern, $text[$pos - 1]);
                    $afterOk  = $after >= strlen($text) || !preg_match('/[a-zA-Z0-9_\x7f-\xff]/', $text[$after]);
                    if ($beforeOk && $afterOk) {
                        $positions[] = [
                            'start' => $doc->getStartFilePos() + $pos,
                            'end' => $doc->getStartFilePos() + $after,
//                            'replacement' => '\\' . $replacement,
                            'replacement' => $replacement,
                        ];
                    }
                    $offset = $pos + 1;
                }
            }
        }

        return $positions;
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

    /**
     * In the case of `use Namespaced\Traitname;` by `nette/latte`, the trait uses the full namespace but it is not
     * preceded by a backslash. When everything is moved up a namespace level, this is a problem. I think being
     * explicit about the namespace being a full namespace rather than a relative one should fix this.
     *
     * We will scan the file for `use Namespaced\Traitname` and replace it with `use \Namespaced\Traitname;`.
     *
     * @see https://github.com/nette/latte/blob/0ac0843a459790d471821f6a82f5d13db831a0d3/src/Latte/Loaders/FileLoader.php#L20
     *
     * @param string $phpFileContent
     * @param DiscoveredSymbols $discoveredNamespaceSymbols
     */
    protected function prepareRelativeNamespaces(string $phpFileContent, DiscoveredSymbols $discoveredNamespaceSymbols): string
    {
        $discoveredNamespaceSymbolsArray = $discoveredNamespaceSymbols->toArray();

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $ast = $parser->parse($phpFileContent);
        } catch (\PhpParser\Error $e) {
            $this->logger->warning("Skipping ::prepareRelativeNamespaces() in file due to parse error: " . $e->getMessage());
            return $phpFileContent;
        }

        $traverser = new NodeTraverser();
        $visitor = new class($discoveredNamespaceSymbolsArray) extends \PhpParser\NodeVisitorAbstract {

            public int $countChanges = 0;
            /** @var string[] */
            protected array $discoveredNamespaces;

            protected Node $lastNode;

            /**
             * The list of `use Namespace\Subns;` statements in the file.
             *
             * @var string[]
             */
            protected array $using = [];

            /**
             * @param NamespaceSymbol[] $discoveredNamespaceSymbols
             */
            public function __construct(array $discoveredNamespaceSymbols)
            {

                $this->discoveredNamespaces = array_map(
                    fn(NamespaceSymbol $symbol) => $symbol->getOriginalSymbol(),
                    $discoveredNamespaceSymbols
                );
            }

            public function leaveNode(Node $node)
            {

                if ($node instanceof Namespace_) {
                    $this->using[] = $node->name->name;
                    $this->lastNode = $node;
                    return $node;
                }
                // Probably the namespace declaration
                if (empty($this->lastNode) && $node instanceof Name) {
                    $this->using[] = $node->name;
                    $this->lastNode = $node;
                    return $node;
                }
                if ($node instanceof Name) {
                    return $node;
                }
                if ($node instanceof Use_) {
                    foreach ($node->uses as $use) {
                        $use->name->name = ltrim($use->name->name, '\\') ?: (function () {
                            throw new Exception('$use->name->name was empty');
                        })();
                        $this->using[] = $use->name->name;
                    }
                    $this->lastNode = $node;
                    return $node;
                }
                if ($node instanceof UseItem) {
                    return $node;
                }

                $nameNodes = [];

                $docComment = $node->getDocComment();
                if ($docComment) {
                    foreach ($this->discoveredNamespaces as $namespace) {
                        $updatedDocCommentText = preg_replace(
                            '/(.*\*\s*@\w+\s+)(' . preg_quote($namespace, '/') . ')/',
                            '$1\\\\$2',
                            $docComment->getText(),
                            1,
                            $count
                        );
                        if ($count > 0) {
                            $this->countChanges++;
                            $node->setDocComment(new Doc($updatedDocCommentText));
                            break;
                        }
                    }
                }

                if ($node instanceof TraitUse) {
                    $nameNodes = array_merge(
                        $nameNodes,
                        array_filter(
                            $node->traits,
                            fn($node) => !($node instanceof FullyQualified)
                        )
                    );
                }

                if ($node instanceof Param
                    && $node->type instanceof Name
                    && !($node->type instanceof FullyQualified)) {
                    $nameNodes[] = $node->type;
                }

                if ($node instanceof \PhpParser\Node\NullableType
                    && $node->type instanceof Name
                    && !($node->type instanceof FullyQualified)) {
                    $nameNodes[] = $node->type;
                }

                if ($node instanceof \PhpParser\Node\Stmt\ClassMethod
                    && $node->returnType instanceof Name
                    && !($node->returnType instanceof FullyQualified)) {
                    $nameNodes[] = $node->returnType;
                }

                if ($node instanceof ClassConstFetch
                    && $node->class instanceof Name
                    && !($node->class instanceof FullyQualified)) {
                    $nameNodes[] = $node->class;
                }

                if ($node instanceof Node\Expr\New_
                    && $node->class instanceof Name
                    && !($node->class instanceof FullyQualified)) {
                    $nameNodes[] = $node->class;
                }

                if ($node instanceof StaticPropertyFetch
                    && $node->class instanceof Name
                    && !($node->class instanceof FullyQualified)) {
                    $nameNodes[] = $node->class;
                }

                if (property_exists($node, 'name')
                    && $node->name instanceof Name
                    && !($node->name instanceof FullyQualified)
                ) {
                    $nameNodes[] = $node->name;
                }

                if ($node instanceof StaticCall) {
                    if (!method_exists($node->class, 'isFullyQualified') || !$node->class->isFullyQualified()) {
                        $nameNodes[] = $node->class;
                    }
                }

                if ($node instanceof \PhpParser\Node\Stmt\TryCatch) {
                    foreach ($node->catches as $catch) {
                        foreach ($catch->types as $catchType) {
                            if ($catchType instanceof Name
                                && !($catchType instanceof FullyQualified)
                            ) {
                                $nameNodes[] = $catchType;
                            }
                        }
                    }
                }

                if ($node instanceof Class_) {
                    foreach ($node->implements as $implement) {
                        if ($implement instanceof Name
                            && !($implement instanceof FullyQualified)) {
                            $nameNodes[] = $implement;
                        }
                    }
                }
                if ($node instanceof \PhpParser\Node\Expr\Instanceof_
                    && $node->class instanceof Name
                    && !($node->class instanceof FullyQualified)) {
                    $nameNodes[] = $node->class;
                }

                foreach ($nameNodes as $nameNode) {
                    if (!property_exists($nameNode, 'name')) {
                        continue;
                    }
                    // If the name contains a `\` but does not begin with one, it may be a relative namespace;
                    if (false !== strpos($nameNode->name, '\\') && 0 !== strpos($nameNode->name, '\\')) {
                        $parts = explode('\\', $nameNode->name);
                        array_pop($parts);
                        $namespace = implode('\\', $parts);
                        if (in_array($namespace, $this->discoveredNamespaces)) {
                            $nameNode->name = $this->prefixWithSingleLeadingSlash($nameNode->name);
                            $this->countChanges++;
                        } else {
                            foreach ($this->using as $namespaceBase) {
                                if (in_array($namespaceBase . '\\' . $namespace, $this->discoveredNamespaces)) {
                                    $nameNode->name = $this->prefixWithSingleLeadingSlash($namespaceBase . '\\' . $nameNode->name);
                                    $this->countChanges++;
                                    break;
                                }
                            }
                        }
                    }
                }
                $this->lastNode = $node;
                return $node;
            }

            /**
             * "brian" -> "\brian"
             * "\brian" -> "\brian"
             * "\\brian" -> "\brian"
             */
            private function prefixWithSingleLeadingSlash(string $maybePrefixed): string
            {
                return preg_replace('/^\\+/', '\\', '\\' . $maybePrefixed);
            }
        };
        $traverser->addVisitor($visitor);

        $modifiedStmts = $traverser->traverse($ast);

        return $visitor->countChanges == 0
            ? $phpFileContent
            : (new Standard())->prettyPrintFile($modifiedStmts);
    }

    public function prefixComposerAutoloadFiles(string $absoluteDirectory): void
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
            'platform_check.php',
        ];

        $composerFiles = [];

        $discoveredFiles = new DiscoveredFiles();

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
        if (empty($composerFiles)) {
            return;
        }

        $discoveredSymbols = new DiscoveredSymbols();

        $composerAutoloadNamespaceSymbol = new NamespaceSymbol('Composer\\Autoload');
        $composerAutoloadNamespaceSymbol->setLocalReplacement(
            $this->config->getNamespacePrefix() . '\\Composer\\Autoload'
        );
        $discoveredSymbols->add($composerAutoloadNamespaceSymbol);

        $composerNamespaceSymbol = new NamespaceSymbol('Composer');
        $composerNamespaceSymbol->setLocalReplacement(
            $this->config->getNamespacePrefix() . '\\Composer'
        );
        $discoveredSymbols->add($composerNamespaceSymbol);

        if (isset($composerFiles['ClassLoader.php'])) {
            $classLoaderSymbol = new ClassSymbol(
                'Composer\\Autoload\\ClassLoader',
                $composerFiles['ClassLoader.php'],
                false,
                $composerAutoloadNamespaceSymbol
            );
            $discoveredSymbols->add($classLoaderSymbol);
        }

        if (isset($composerFiles['installed.php'])) {
            $installedVersions = new ClassSymbol(
                'Composer\\InstalledVersions',
                $composerFiles['installed.php'],
                false,
                $composerNamespaceSymbol
            );
            $discoveredSymbols->add($installedVersions);
        }


        $this->replaceInFiles($discoveredSymbols, $discoveredFiles->getFiles());
    }
}
