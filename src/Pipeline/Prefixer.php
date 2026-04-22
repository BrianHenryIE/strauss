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
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\NamespacedSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Exception;
use League\Flysystem\FilesystemException;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Function_;
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

        $absoluteFilePathsArray = array_map(
            fn($file) => $file->getSourcePath(),
            $phpFiles
        );

        foreach ($absoluteFilePathsArray as $fileAbsolutePath) {
            $relativeFilePath = $this->filesystem->getRelativePath(dirname($this->config->getAbsoluteTargetDirectory()), $fileAbsolutePath);

            if ($this->filesystem->directoryExists($fileAbsolutePath)) {
                $this->logger->debug("is_dir() / nothing to do : {relativeFilePath}", [
                    'relativeFilePath' => $relativeFilePath
                ]);
                continue;
            }

            if (!$this->filesystem->fileExists($fileAbsolutePath)) {
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

            $updatedContents = $this->replaceInString($discoveredSymbols, $contents);

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
        $functions = $discoveredSymbols->getDiscoveredFunctions()->getToRename();

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
            return $contents;
        }

        $positions = array_merge(
            $positions,
            $this->replaceNamespaces($ast, $discoveredSymbols->getNamespaces()->getToRename()),
            $this->findGlobalSymbolsPositionsInAst($ast, $discoveredSymbols->getGlobalClassesInterfacesTraits()->getToRename()),
            $this->replaceConstFetchNamespaces($discoveredSymbols, $ast),
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

        foreach ($functions as $functionSymbol) {
            $contents = $this->replaceFunctions($contents, $functionSymbol);
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

        $this->logger->debug('Searching in {filename} for {count} symbol as string', [
            'filename' => basename($fileAbsolutePath),
            'count' => count($discoveredSymbols->toArray()),
        ]);

        // TODO: filter to only namespaces of more than a single depth.
        /** @var NamespaceSymbol $namespaceSymbol */
        foreach ($discoveredSymbols->getNamespaces()->getToRename() as $namespaceSymbol) {
            $this->logger->debug('Searching in {filename} for {type} {name}', [
                'filename' => basename($fileAbsolutePath),
                'type' => basename(get_class($namespaceSymbol)),
                'name' => $namespaceSymbol->getOriginalLocalName()
            ]);

            $contents = $this->replaceSingleClassnameInString($contents, $namespaceSymbol);
        }

        /** @var ClassSymbol $classSymbol */
        foreach ($discoveredSymbols->getClassesInterfacesTraits()->getToRename() as $classSymbol) {
            $this->logger->debug('Searching in {filename} for {type} {name}', [
                'filename' => basename($fileAbsolutePath),
                'type' => basename(get_class($classSymbol)),
                'name' => $classSymbol->getOriginalLocalName(),
            ]);

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
                && $node->name instanceof Name\FullyQualified;
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

    protected function replaceNamespaces(array $ast, DiscoveredSymbols $namespaceChanges): array
    {
        if (empty($namespaceChanges->toArray())) {
            return [];
        }

        $nsMap = [];
        foreach ($namespaceChanges as $change) {
            $original = rtrim($change->getOriginalSymbol(), '\\');
            $replacement = rtrim($change->getReplacementFqdnName(), '\\');
            $nsMap[$original] = $replacement;
        }
        uksort($nsMap, fn($a, $b) => strlen($b) - strlen($a));

        $nodeFinder = new NodeFinder();
        $positions = [];
        $handled = [];

        $findMatch = function (string $nameStr) use ($nsMap): ?array {
            foreach ($nsMap as $original => $replacement) {
                if ($nameStr === $original || str_starts_with($nameStr, $original . '\\')) {
                    return ['original' => $original, 'replacement' => $replacement];
                }
            }
            return null;
        };

        $findPrefixMatch = function (string $nameStr) use ($nsMap): ?array {
            foreach ($nsMap as $original => $replacement) {
                if (str_starts_with($nameStr, $original . '\\')) {
                    return ['original' => $original, 'replacement' => $replacement];
                }
            }
            return null;
        };

        $prefixed = function (string $nameStr, string $original, string $replacement): string {
            return $replacement . substr($nameStr, strlen($original));
        };

        // A: namespace declarations — keep relative (no leading \)
        foreach ($nodeFinder->findInstanceOf($ast, \PhpParser\Node\Stmt\Namespace_::class) as $ns) {
            if ($ns->name !== null && ($match = $findMatch($ns->name->toString()))) {
                $positions[] = [
                    'start' => $ns->name->getStartFilePos(),
                    'end' => $ns->name->getEndFilePos() + 1,
                    'replacement' => $prefixed($ns->name->toString(), $match['original'], $match['replacement']),
                ];
                $handled[$ns->name->getStartFilePos()] = true;
            }
        }

        // B: use items and group-use prefixes — keep relative (no leading \)
        foreach ($nodeFinder->findInstanceOf($ast, \PhpParser\Node\UseItem::class) as $item) {
            if ($match = $findMatch($item->name->toString())) {
                $positions[] = [
                    'start' => $item->name->getStartFilePos(),
                    'end' => $item->name->getEndFilePos() + 1,
                    'replacement' => $prefixed($item->name->toString(), $match['original'], $match['replacement']),
                ];
                $handled[$item->name->getStartFilePos()] = true;
            }
        }
        foreach ($nodeFinder->findInstanceOf($ast, \PhpParser\Node\Stmt\GroupUse::class) as $groupUse) {
            if ($groupUse->prefix !== null && ($match = $findMatch($groupUse->prefix->toString()))) {
                $positions[] = [
                    'start' => $groupUse->prefix->getStartFilePos(),
                    'end' => $groupUse->prefix->getEndFilePos() + 1,
                    'replacement' => $prefixed($groupUse->prefix->toString(), $match['original'], $match['replacement']),
                ];
                $handled[$groupUse->prefix->getStartFilePos()] = true;
            }
        }

        // C: fully-qualified Name nodes — retain leading \
        foreach ($nodeFinder->find($ast, function (Node $node) use ($findMatch) {
            return $node instanceof Name\FullyQualified && $findMatch($node->toString()) !== null;
        }) as $name) {
            if (isset($handled[$name->getStartFilePos()])) {
                continue;
            }
            $match = $findMatch($name->toString());
            $positions[] = [
                'start' => $name->getStartFilePos(),
                'end' => $name->getEndFilePos() + 1,
                'replacement' => '\\' . $prefixed($name->toString(), $match['original'], $match['replacement']),
            ];
            $handled[$name->getStartFilePos()] = true;
        }

        // D: relative Name nodes used as namespace prefixes in code — promote to FQ.
        foreach ($nodeFinder->find($ast, function (Node $node) use ($findPrefixMatch) {
            return $node instanceof Name
                && !($node instanceof Name\FullyQualified)
                && $findPrefixMatch($node->toString()) !== null;
        }) as $name) {
            if (isset($handled[$name->getStartFilePos()])) {
                continue;
            }
            $match = $findPrefixMatch($name->toString());
            $positions[] = [
                'start' => $name->getStartFilePos(),
                'end' => $name->getEndFilePos() + 1,
                'replacement' => '\\' . $prefixed($name->toString(), $match['original'], $match['replacement']),
            ];
        }

        // Doc comments: scan for \OriginalNamespace references in @param/@return/etc.
        foreach ($nodeFinder->find($ast, function (Node $node) {
            return $node->getDocComment() !== null;
        }) as $node) {
            $doc = $node->getDocComment();
            $text = $doc->getText();
            foreach ($nsMap as $original => $replacement) {
                $docSearchStr = '\\' . $original;
                $docSearchLen = strlen($docSearchStr);
                $offset = 0;
                while (($pos = strpos($text, $docSearchStr, $offset)) !== false) {
                    $after = $pos + $docSearchLen;
                    if ($after >= strlen($text)
                        || !preg_match('/[a-zA-Z0-9_\x7f-\xff]/', $text[$after])
                    ) {
                        $positions[] = [
                            'start' => $doc->getStartFilePos() + $pos,
                            'end' => $doc->getStartFilePos() + $after,
                            'replacement' => '\\' . $replacement,
                        ];
                    }
                    $offset = $pos + 1;
                }
            }
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
    protected function replaceSingleClassnameInString(string $contents, DiscoveredSymbol $symbol): string
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
                            [\'"]
                            [\\\\]{0,2}
)
                        ('
                            . str_replace('\\', '[\\\\]{1,2}', $originalSymbolString) .
                        ')(
                        '
                      . ( $alsoSearchForVariableClassname ? '([\\\\]{1,2}\$[a-zA-Z0-9_\x7f-\xff]*)?' : '' ) .
                      ( $alsoSearchForStaticProperty ? '(:{2}\$[a-zA-Z0-9_\x7f-\xff]*)?' : '' ) .
                      '
                            [\'"]
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
    public function findGlobalSymbolsPositionsInAst(array $ast, DiscoveredSymbols $globalClassesInterfacesTraitsToRename): array
    {
        if (empty($globalClassesInterfacesTraitsToRename)) {
            return [];
        }

        $nodeFinder = new NodeFinder();
        $positions = [];

        // Replace \Classname (fully qualified) references in any namespace context.
        $fqNodes = $nodeFinder->find($ast, function (Node $node) use ($globalClassesInterfacesTraitsToRename, &$positions) {
            if ($node->getAttribute('comments')) {
                // TODO. This is recording comments repeatedly. Duplicates are later removed, but it'd be better to just not add them.
                /** @var \PhpParser\Comment\Doc $comment */
                foreach ($node->getAttribute('comments') as $comment) {
                    $positions = array_merge(
                        $positions,
                        $this->findGlobalSymbolsPositionsInComment($comment, $globalClassesInterfacesTraitsToRename)
                    );
                }
            }
            if (!( $node instanceof Name\FullyQualified )) {
                return false;
            }
            return $this->hasGlobalSymbolForNode($node, $globalClassesInterfacesTraitsToRename);
        });

        foreach ($fqNodes as $node) {
            $positions[] = [
                'start' => $node->getStartFilePos(),
                'end' => $node->getEndFilePos() + 1,
                'replacement' => '\\' . $this->getReplacementStringForNode($node, $globalClassesInterfacesTraitsToRename),
            ];
        }

        // In named namespaces, `use Classname;` must become `use PrefixedClassname as Classname;`
        // so that unqualified references within the namespace continue to resolve correctly.
        $namedNamespaces = array_filter(
            $nodeFinder->findInstanceOf($ast, \PhpParser\Node\Stmt\Namespace_::class),
            fn($ns) => $ns->name !== null
        );
        foreach ($namedNamespaces as $nsStmt) {
            $useItems = $nodeFinder->findInstanceOf($nsStmt->stmts ?? [], \PhpParser\Node\UseItem::class);
            foreach ($useItems as $useItem) {
                if (!($useItem->name instanceof Name\FullyQualified)
                     && $globalClassesInterfacesTraitsToRename->getClass($useItem->name->toString())
                ) {
                    $symbol = $globalClassesInterfacesTraitsToRename->getClass($useItem->name->toString());
                    if ($symbol->isDoRename()) {
                        $replacementClassname = $symbol->getLocalReplacement();
                        $useClassname = array_reverse(explode('\\', $useItem->name->toString()))[0];

                        $replacementString = $symbol->getLocalReplacement();
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
        }

        // In global namespace context (either implicit, or explicit `namespace {}`), replace
        // unqualified class name references and class/interface/trait/enum declarations.
        $globalStmts = [];
        foreach ($ast as $node) {
            if ($node instanceof \PhpParser\Node\Stmt\Namespace_) {
                if ($node->name === null) {
                    $globalStmts = array_merge($globalStmts, $node->stmts ?? []);
                }
            } else {
                $globalStmts[] = $node;
            }
        }

        $classLike = $nodeFinder->find($globalStmts, function (Node $node) use ($globalClassesInterfacesTraitsToRename) {
            return ($node instanceof \PhpParser\Node\Stmt\Class_
                || $node instanceof \PhpParser\Node\Stmt\Interface_
                || $node instanceof \PhpParser\Node\Stmt\Trait_
                || $node instanceof \PhpParser\Node\Stmt\Enum_)
                && isset($node->name)
                && $node->name instanceof \PhpParser\Node\Identifier
                && (
                       $globalClassesInterfacesTraitsToRename->getClass($node->name->name)
                       || $globalClassesInterfacesTraitsToRename->getInterface($node->name->name)
                       || $globalClassesInterfacesTraitsToRename->getTrait($node->name->name)
                   );
        });
        foreach ($classLike as $node) {
            $positions[] = [
                'start' => $node->name->getStartFilePos(),
                'end' => $node->name->getEndFilePos() + 1,
                'replacement' => $this->getReplacementStringForNode($node, $globalClassesInterfacesTraitsToRename),
            ];
        }

        $unqualifiedNameNodes = $nodeFinder->find($globalStmts, function (Node $node) use ($globalClassesInterfacesTraitsToRename) {
            return $node instanceof Name
                && !($node instanceof Name\FullyQualified)
                   && $this->hasGlobalSymbolForNode($node, $globalClassesInterfacesTraitsToRename);
        });
        foreach ($unqualifiedNameNodes as $node) {
            $positions[] = [
                'start' => $node->getStartFilePos(),
                'end' => $node->getEndFilePos() + 1,
                'replacement' => $this->getReplacementStringForNode($node, $globalClassesInterfacesTraitsToRename)
            ];
        }

        return $positions;
    }

    protected function hasGlobalSymbolForNode(\PhpParser\Node $node, DiscoveredSymbols $discoveredSymbols): bool
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
    protected function getGlobalSymbolForNode(\PhpParser\Node $node, DiscoveredSymbols $discoveredSymbols): ?DiscoveredSymbol
    {
        if ($node instanceof \PhpParser\Node\Stmt\Class_) {
            return $discoveredSymbols->getClass($node->name->toString());
        }
        if ($node instanceof \PhpParser\Node\Stmt\Interface_) {
            return $discoveredSymbols->getInterface($node->name->toString());
        }
        if ($node instanceof \PhpParser\Node\Stmt\Trait_) {
            return $discoveredSymbols->getTrait($node->name->toString());
        }
        return $discoveredSymbols->getClass($node->toString())
               ?? $discoveredSymbols->getInterface($node->toString())
                ?? $discoveredSymbols->getTrait($node->toString());
    }

    protected function getReplacementStringForNode(\PhpParser\Node $node, DiscoveredSymbols $discoveredSymbols)
    {
        $globalSymbol = $this->getGlobalSymbolForNode($node, $discoveredSymbols);
        if ($globalSymbol) {
            return $globalSymbol->getLocalReplacement();
        }
        return $node->toString();
    }


    protected function checkPregError(): void
    {
        $matchingError = preg_last_error();
        if (0 !== $matchingError) {
            throw new Exception(preg_last_error_msg());
        }
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

    protected function replaceFunctions(string $contents, FunctionSymbol $functionSymbol): string
    {
        $originalFunctionString = $functionSymbol->getOriginalSymbol();
        $replacementFunctionString = $functionSymbol->getLocalReplacement();

        if ($originalFunctionString === $replacementFunctionString) {
            return $contents;
        }

        $nodeFinder = new NodeFinder();
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        try {
            $ast = $parser->parse($contents);
        } catch (\PhpParser\Error $e) {
            $this->logger->warning("Skipping ::replaceFunctions() in file due to parse error: " . $e->getMessage());
            return $contents;
        }

        $positions = [];

        // Function declarations (global only)
        $functionDefs = $nodeFinder->findInstanceOf($ast, Function_::class);
        foreach ($functionDefs as $func) {
            if ($func->name->name === $originalFunctionString) {
                $positions[] = [
                    'start' => $func->name->getStartFilePos(),
                    'end' => $func->name->getEndFilePos() + 1,
                ];
            }
        }

        // Calls (global only)
        $calls = $nodeFinder->findInstanceOf($ast, FuncCall::class);
        foreach ($calls as $call) {
            if ($call->name instanceof Name &&
                $call->name->toString() === $originalFunctionString
            ) {
                $positions[] = [
                    'start' => $call->name->getStartFilePos(),
                    'end' => $call->name->getEndFilePos() + 1,
                ];
            }
        }

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

        foreach ($calls as $call) {
            if ($call->name instanceof Name &&
                in_array($call->name->toString(), $functionsUsingCallable)
                && isset($call->args[0])
                && $call->args[0] instanceof Arg
                && $call->args[0]->value instanceof String_
                && $call->args[0]->value->value === $originalFunctionString
            ) {
                $positions[] = [
                    'start' => $call->args[0]->value->getStartFilePos() + 1, // do not change quotes
                    'end' => $call->args[0]->value->getEndFilePos(),
                ];
            }
        }

        if (empty($positions)) {
            return $contents;
        }

        // We sort by start, from the end - so as not to break the positions after the substitution
        usort($positions, fn($a, $b) => $b['start'] <=> $a['start']);

        foreach ($positions as $pos) {
            $contents = substr_replace($contents, $replacementFunctionString, $pos['start'], $pos['end'] - $pos['start']);
        }
        return $contents;
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

                if ($node instanceof \PhpParser\Node\Stmt\Namespace_) {
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
                if ($node instanceof \PhpParser\Node\Stmt\Use_) {
                    foreach ($node->uses as $use) {
                        $use->name->name = ltrim($use->name->name, '\\') ?: (function () {
                            throw new Exception('$use->name->name was empty');
                        })();
                        $this->using[] = $use->name->name;
                    }
                    $this->lastNode = $node;
                    return $node;
                }
                if ($node instanceof \PhpParser\Node\UseItem) {
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
                            $node->setDocComment(new \PhpParser\Comment\Doc($updatedDocCommentText));
                            break;
                        }
                    }
                }

                if ($node instanceof \PhpParser\Node\Stmt\TraitUse) {
                    $nameNodes = array_merge(
                        $nameNodes,
                        array_filter(
                            $node->traits,
                            fn($node) => !($node instanceof \PhpParser\Node\Name\FullyQualified)
                        )
                    );
                }

                if ($node instanceof \PhpParser\Node\Param
                    && $node->type instanceof Name
                    && !($node->type instanceof \PhpParser\Node\Name\FullyQualified)) {
                    $nameNodes[] = $node->type;
                }

                if ($node instanceof \PhpParser\Node\NullableType
                    && $node->type instanceof Name
                    && !($node->type instanceof \PhpParser\Node\Name\FullyQualified)) {
                    $nameNodes[] = $node->type;
                }

                if ($node instanceof \PhpParser\Node\Stmt\ClassMethod
                    && $node->returnType instanceof Name
                    && !($node->returnType instanceof \PhpParser\Node\Name\FullyQualified)) {
                    $nameNodes[] = $node->returnType;
                }

                if ($node instanceof ClassConstFetch
                    && $node->class instanceof Name
                    && !($node->class instanceof \PhpParser\Node\Name\FullyQualified)) {
                    $nameNodes[] = $node->class;
                }

                if ($node instanceof Node\Expr\New_
                    && $node->class instanceof Name
                    && !($node->class instanceof \PhpParser\Node\Name\FullyQualified)) {
                    $nameNodes[] = $node->class;
                }

                if ($node instanceof \PhpParser\Node\Expr\StaticPropertyFetch
                    && $node->class instanceof Name
                    && !($node->class instanceof \PhpParser\Node\Name\FullyQualified)) {
                    $nameNodes[] = $node->class;
                }

                if (property_exists($node, 'name')
                    && $node->name instanceof Name
                    && !($node->name instanceof \PhpParser\Node\Name\FullyQualified)
                ) {
                    $nameNodes[] = $node->name;
                }

                if ($node instanceof \PhpParser\Node\Expr\StaticCall) {
                    if (!method_exists($node->class, 'isFullyQualified') || !$node->class->isFullyQualified()) {
                        $nameNodes[] = $node->class;
                    }
                }

                if ($node instanceof \PhpParser\Node\Stmt\TryCatch) {
                    foreach ($node->catches as $catch) {
                        foreach ($catch->types as $catchType) {
                            if ($catchType instanceof Name
                                && !($catchType instanceof \PhpParser\Node\Name\FullyQualified)
                            ) {
                                $nameNodes[] = $catchType;
                            }
                        }
                    }
                }

                if ($node instanceof \PhpParser\Node\Stmt\Class_) {
                    foreach ($node->implements as $implement) {
                        if ($implement instanceof Name
                            && !($implement instanceof \PhpParser\Node\Name\FullyQualified)) {
                            $nameNodes[] = $implement;
                        }
                    }
                }
                if ($node instanceof \PhpParser\Node\Expr\Instanceof_
                    && $node->class instanceof Name
                    && !($node->class instanceof \PhpParser\Node\Name\FullyQualified)) {
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
            $file = new File(
                $absoluteDirectory . '/composer/' . $filePath,
                $filePath,
                $absoluteDirectory . '/composer/' . $filePath,
            );
            $discoveredFiles->add($file);
            $composerFiles[$filePath] = $file;
        }

        $composerAutoloadNamespaceSymbol = new NamespaceSymbol('Composer\\Autoload');
        $composerAutoloadNamespaceSymbol->setLocalReplacement(
            $this->config->getNamespacePrefix() . '\\Composer\\Autoload'
        );

        $composerNamespaceSymbol = new NamespaceSymbol('Composer');
        $composerNamespaceSymbol->setLocalReplacement(
            $this->config->getNamespacePrefix() . '\\Composer'
        );

        $classLoaderSymbol = new ClassSymbol(
            'Composer\\Autoload\\ClassLoader',
            $composerFiles['ClassLoader.php'],
            false,
            $composerAutoloadNamespaceSymbol
        );

        $installedVersions = new ClassSymbol(
            'Composer\\InstalledVersions',
            $composerFiles['installed.php'],
            false,
            $composerNamespaceSymbol
        );

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add(
            $composerNamespaceSymbol
        );
        $discoveredSymbols->add(
            $composerAutoloadNamespaceSymbol
        );
        $discoveredSymbols->add($classLoaderSymbol);
        $discoveredSymbols->add($installedVersions);

        $this->replaceInFiles($discoveredSymbols, $discoveredFiles->getFiles());
    }
}
