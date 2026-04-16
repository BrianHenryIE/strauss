<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\PrefixerConfigInterface;
use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Helpers\NamespaceSort;
use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
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
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeAbstract;
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
            if (!$this->config->isTargetDirectoryVendor()
                && !$file->isDoCopy()
            ) {
                continue;
            }

            if ($this->filesystem->directoryExists($file->getTargetAbsolutePath())) {
                $this->logger->debug("is_dir() / nothing to do : {$file->getTargetAbsolutePath()}");
                continue;
            }

            if (!$file->isPhpFile()) {
                continue;
            }

            if (!$this->filesystem->fileExists($file->getTargetAbsolutePath())) {
                $this->logger->warning("Expected file does not exist: {$file->getTargetAbsolutePath()}");
                continue;
            }

            $this->logger->debug("Updating contents of file: {targetAbsolutePath}", [
                'targetAbsolutePath' => $file->getTargetAbsolutePath()
            ]);

            /**
             * Throws an exception, but unlikely to happen.
             */
            $contents = $this->filesystem->read($file->getTargetAbsolutePath());

            $updatedContents = $this->replaceInString($discoveredSymbols, $contents);

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
    }

    /**
     * @param DiscoveredSymbols $discoveredSymbols
     * @param string[] $absoluteFilePathsArray
     *
     * @return void
     * @throws FilesystemException
     */
    public function replaceInProjectFiles(DiscoveredSymbols $discoveredSymbols, array $absoluteFilePathsArray): void
    {

        foreach ($absoluteFilePathsArray as $fileAbsolutePath) {
            $relativeFilePath = $this->filesystem->getRelativePath(dirname($this->config->getAbsoluteTargetDirectory()), $fileAbsolutePath);

            if ($this->filesystem->directoryExists($fileAbsolutePath)) {
                $this->logger->debug("is_dir() / nothing to do : {$relativeFilePath}");
                continue;
            }

            if (!$this->filesystem->fileExists($fileAbsolutePath)) {
                $this->logger->warning("Expected file does not exist: {$relativeFilePath}");
                continue;
            }

            $this->logger->debug("Updating contents of file: {$relativeFilePath}");

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
    public function replaceInString(DiscoveredSymbols $discoveredSymbols, string $contents): string
    {
        $globalPrefix = $this->config->getClassmapPrefix();

        $namespacesChanges = $discoveredSymbols->getDiscoveredNamespaceChanges($this->config->getNamespacePrefix());
        $constants = $discoveredSymbols->getDiscoveredConstantChanges($this->config->getConstantsPrefix());
        $classes = $discoveredSymbols->getGlobalClassChanges();
        $functions = $discoveredSymbols->getDiscoveredFunctionChanges();

        $contents = $this->prepareRelativeNamespaces($contents, $namespacesChanges);

        if ($globalPrefix) {
            // Prepend <?php if absent so php-parser treats the content as PHP code rather
            // than inline HTML. The offset is subtracted from all collected positions below.
            $phpOpenerLen = 0;
            $parseContent = $contents;
            if (stripos(ltrim($contents), '<?') !== 0) {
                $phpOpenerLen = strlen("<?php\n");
                $parseContent = "<?php\n" . $contents;
            }

            // Append enough closing braces to satisfy the parser for partial snippets that
            // have unclosed class/function/namespace bodies.
            $open = substr_count($parseContent, '{');
            $close = substr_count($parseContent, '}');
            $parseContent .= str_repeat('}', max(0, $open - $close));

            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $errorHandler = new \PhpParser\ErrorHandler\Collecting();
            $ast = $parser->parse($parseContent, $errorHandler);

            if ($ast === null) {
                $this->logger->warning("Skipping ::replaceClassname() in file due to parse failure.");
            } else {
                $positions = $this->findGlobalSymbolsPositionsInAst($ast, $discoveredSymbols);

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

                foreach ($positions as $pos) {
                    $contents = substr_replace($contents, $pos['replacement'], $pos['start'], $pos['end'] - $pos['start']);
                }
            }

            foreach ($classes as $classSymbol) {
                $contents = $this->replaceSingleClassnameInString($contents, $classSymbol);
            }
        }

        // TODO: Move this out of the loop.
        $namespacesChangesStrings = [];
        foreach ($namespacesChanges as $originalNamespace => $namespaceSymbol) {
            if (in_array($originalNamespace, $this->config->getExcludeNamespacesFromPrefixing())) {
                $this->logger->info("Skipping namespace: $originalNamespace");
                continue;
            }
            $namespacesChangesStrings[$originalNamespace] = $namespaceSymbol->getReplacement();
        }
        // This matters... it shouldn't.
        uksort($namespacesChangesStrings, new NamespaceSort(NamespaceSort::SHORTEST));
        foreach ($namespacesChangesStrings as $originalNamespace => $replacementNamespace) {
            $contents = $this->replaceNamespace($contents, $originalNamespace, $replacementNamespace);
        }

        if (!is_null($this->config->getConstantsPrefix())) {
            $contents = $this->replaceConstants($contents, $constants, $this->config->getConstantsPrefix());
        }

        foreach ($functions as $functionSymbol) {
            $contents = $this->replaceFunctions($contents, $functionSymbol);
        }

        $contents = $this->replaceConstFetchNamespaces($discoveredSymbols, $contents);

        return $contents;
    }

    protected function replaceConstFetchNamespaces(DiscoveredSymbols $symbols, string $contents): string
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        try {
            $ast = $parser->parse($contents);
        } catch (\PhpParser\Error $e) {
            $this->logger->warning("Skipping ::replaceConstFetchNamespaces() in file due to parse error: " . $e->getMessage());
            return $contents;
        }

        $namespaceSymbols = $symbols->getDiscoveredNamespaces($this->config->getNamespacePrefix());
        if (empty($namespaceSymbols)) {
            return $contents;
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

            if ($namespace && isset($namespaceSymbols[$namespace])) {
                $replacementNamespace = $namespaceSymbols[$namespace]->getReplacement();
                $parts[0] = $replacementNamespace;
                $newName = '\\' . implode('\\', $parts);

                $positions[] = [
                    'start' => $fetch->name->getStartFilePos(),
                    'end' => $fetch->name->getEndFilePos() + 1,
                    'replacement' => $newName,
                ];
            }
        }

        usort($positions, fn($a, $b) => $b['start'] <=> $a['start']);

        foreach ($positions as $pos) {
            $contents = substr_replace($contents, $pos['replacement'], $pos['start'], $pos['end'] - $pos['start']);
        }

        return $contents;
    }

    /**
     * TODO: Test against traits.
     *
     * @param string $contents The text to make replacements in.
     * @param string $originalNamespace
     * @param string $replacement
     *
     * @return string The updated text.
     */
    public function replaceNamespace(string $contents, string $originalNamespace, string $replacement): string
    {
        // Normalize: strip any trailing backslashes that callers may pass.
        $originalNamespace = rtrim($originalNamespace, '\\');
        $replacement = rtrim($replacement, '\\');

        $phpOpenerLen = 0;
        $parseContent = $contents;
        if (stripos(ltrim($contents), '<?') !== 0) {
            $phpOpenerLen = strlen("<?php\n");
            $parseContent = "<?php\n" . $contents;
        }

        $open = substr_count($parseContent, '{');
        $close = substr_count($parseContent, '}');
        if ($close > $open) {
            // Extra closing braces (e.g. class body close without matching open): strip trailing
            // } characters until balanced so positions of remaining content are unchanged.
            while (substr_count($parseContent, '}') > substr_count($parseContent, '{')) {
                $last = strrpos($parseContent, '}');
                $parseContent = substr($parseContent, 0, $last) . substr($parseContent, $last + 1);
            }
            $open = substr_count($parseContent, '{');
            $close = substr_count($parseContent, '}');
            $parseContent .= str_repeat('}', max(0, $open - $close));
        } elseif ($open > $close) {
            $parseContent .= str_repeat('}', $open - $close);
        } elseif ($open === 0) {
            // No braces at all: append {} so class/function declarations without a body parse.
            $parseContent .= '{}';
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $errorHandler = new \PhpParser\ErrorHandler\Collecting();
        $ast = $parser->parse($parseContent, $errorHandler);

        if ($ast === null) {
            $this->logger->warning("Skipping ::replaceNamespace() in file due to parse failure.");
            return $contents;
        }

        $nodeFinder = new NodeFinder();
        $positions = [];
        $handled = [];

        // Matches the namespace exactly OR as a prefix (e.g. Ns\Sub).
        $matchesNs = function (string $nameStr) use ($originalNamespace): bool {
            return $nameStr === $originalNamespace
                || str_starts_with($nameStr, $originalNamespace . '\\');
        };

        // Matches only as a namespace prefix (Ns\Sub) — not a standalone name used as a classname.
        $matchesNsPrefix = function (string $nameStr) use ($originalNamespace): bool {
            return str_starts_with($nameStr, $originalNamespace . '\\');
        };

        $prefixed = function (string $nameStr) use ($originalNamespace, $replacement): string {
            return $replacement . substr($nameStr, strlen($originalNamespace));
        };

        // A: namespace declarations — keep relative (no leading \)
        foreach ($nodeFinder->findInstanceOf($ast, \PhpParser\Node\Stmt\Namespace_::class) as $ns) {
            if ($ns->name !== null && $matchesNs($ns->name->toString())) {
                $positions[] = [
                    'start' => $ns->name->getStartFilePos(),
                    'end' => $ns->name->getEndFilePos() + 1,
                    'replacement' => $prefixed($ns->name->toString()),
                ];
                $handled[$ns->name->getStartFilePos()] = true;
            }
        }

        // B: use items and group-use prefixes — keep relative (no leading \)
        foreach ($nodeFinder->findInstanceOf($ast, \PhpParser\Node\UseItem::class) as $item) {
            if ($matchesNs($item->name->toString())) {
                $positions[] = [
                    'start' => $item->name->getStartFilePos(),
                    'end' => $item->name->getEndFilePos() + 1,
                    'replacement' => $prefixed($item->name->toString()),
                ];
                $handled[$item->name->getStartFilePos()] = true;
            }
        }
        foreach ($nodeFinder->findInstanceOf($ast, \PhpParser\Node\Stmt\GroupUse::class) as $groupUse) {
            if ($groupUse->prefix !== null && $matchesNs($groupUse->prefix->toString())) {
                $positions[] = [
                    'start' => $groupUse->prefix->getStartFilePos(),
                    'end' => $groupUse->prefix->getEndFilePos() + 1,
                    'replacement' => $prefixed($groupUse->prefix->toString()),
                ];
                $handled[$groupUse->prefix->getStartFilePos()] = true;
            }
        }

        // C: fully-qualified Name nodes — retain leading \
        foreach ($nodeFinder->find($ast, function (Node $node) use ($matchesNs) {
            return $node instanceof Name\FullyQualified && $matchesNs($node->toString());
        }) as $name) {
            if (isset($handled[$name->getStartFilePos()])) {
                continue;
            }
            $positions[] = [
                'start' => $name->getStartFilePos(),
                'end' => $name->getEndFilePos() + 1,
                'replacement' => '\\' . $prefixed($name->toString()),
            ];
            $handled[$name->getStartFilePos()] = true;
        }

        // D: relative Name nodes used as namespace prefixes in code — promote to FQ.
        // Use prefix-only matching (not exact) to avoid touching bare classname references
        // like type hints (`Mpdf $x`) or implements clauses (`implements Geocoder`).
        foreach ($nodeFinder->find($ast, function (Node $node) use ($matchesNsPrefix) {
            return $node instanceof Name
                && !($node instanceof Name\FullyQualified)
                && $matchesNsPrefix($node->toString());
        }) as $name) {
            if (isset($handled[$name->getStartFilePos()])) {
                continue;
            }
            $positions[] = [
                'start' => $name->getStartFilePos(),
                'end' => $name->getEndFilePos() + 1,
                'replacement' => '\\' . $prefixed($name->toString()),
            ];
        }

        // Doc comments: scan for \OriginalNamespace references in @param/@return/etc.
        // The boundary check allows a following \ (namespace separator) so that
        // \Ns\Class is matched, while \NsExtra is not.
        $docSearchStr = '\\' . $originalNamespace;
        $docSearchLen = strlen($docSearchStr);
        foreach ($nodeFinder->find($ast, function (Node $node) {
            return $node->getDocComment() !== null;
        }) as $node) {
            $doc = $node->getDocComment();
            $text = $doc->getText();
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

        // Strings: handle namespace references embedded in string literals.
        //
        // Three forms a namespace can take in raw source:
        //   1. Double-backslash encoded (namespace has \): "Ns\\Sub\\Class" → $dblNs = 'Ns\\\\Sub'
        //   2. FQ single-backslash: "\Ns\Class" → look for \Ns (boundary-aware)
        //   3. Relative prefix in double-quoted: "Ns\\Class" (no leading \, Ns followed by \\)
        //
        // Boundary rule: the match must not be preceded by an identifier char or \.
        $singleSearchStr = '\\' . $originalNamespace;
        $singleSearchPattern = '/(?<![a-zA-Z0-9_\x7f-\xff\\\\])' . preg_quote($singleSearchStr, '/') . '/';
        $singleReplacement = '\\' . $replacement;
        $hasBackslashInNs = strpos($originalNamespace, '\\') !== false;
        $dblNs = str_replace('\\', '\\\\', $originalNamespace);
        $dblRep = str_replace('\\', '\\\\', $replacement);
        // Pattern for form 3: "Ns\\" where Ns is followed by \\ (namespace separator in dbl-quoted strings).
        $prefixDblPattern = '/(?<![a-zA-Z0-9_\x7f-\xff\\\\])' . preg_quote($originalNamespace, '/') . '(?=\\\\\\\\)/';
        foreach ($nodeFinder->find($ast, function (Node $node) {
            return $node instanceof String_
                || $node instanceof \PhpParser\Node\Scalar\Encapsed;
        }) as $str) {
            $start = $str->getStartFilePos();
            $end = $str->getEndFilePos() + 1;
            $slice = substr($parseContent, $start, $end - $start);
            // Form 1: double-backslash encoded (e.g. "\\Ns\\Sub\\Class").
            if ($hasBackslashInNs && strpos($slice, $dblNs) !== false) {
                $positions[] = ['start' => $start, 'end' => $end, 'replacement' => str_replace($dblNs, $dblRep, $slice)];
                continue;
            }
            // Apply form-3 and form-2 patterns to the same slice (they target different text).
            $newSlice = preg_replace_callback(
                $prefixDblPattern,
                function () use ($dblRep) { return $dblRep; },
                $slice
            );
            if ($newSlice === null) {
                $newSlice = $slice;
            }
            $newSlice = preg_replace_callback(
                $singleSearchPattern,
                function () use ($singleReplacement) { return $singleReplacement; },
                $newSlice
            );
            if ($newSlice !== null && $newSlice !== $slice) {
                $positions[] = ['start' => $start, 'end' => $end, 'replacement' => $newSlice];
            }
        }

        if (empty($positions)) {
            return $contents;
        }

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
        foreach ($positions as $pos) {
            $contents = substr_replace($contents, $pos['replacement'], $pos['start'], $pos['end'] - $pos['start']);
        }
        return $contents;
    }

    protected function findGlobalSymbolsPositionsInComment(Comment $comment, DiscoveredSymbols $globalSymbols): array
    {
        $positions = [];
        foreach ($globalSymbols->getGlobalClassesInterfacesTraits() as $discoveredSymbol) {
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
                    'replacement' => '\\' . $globalSymbol->getReplacement(),
                ];
            }
            $offset = $pos + 1;
        }

        return $positions;
    }

    protected function replaceSingleClassnameInString(string $contents, DiscoveredSymbol $symbol): string
    {

        $originalSymbolString = $symbol->getOriginalSymbolStripPrefix($this->config->getClassmapPrefix());
        $replacementSymblString = $symbol->getReplacement();

        /**
         * Replace classnames in strings, e.g. `is_a( $recurrence, 'CronExpression' )`.
         *
         * `[^a-zA-Z0-9_\x7f-\xff\\\\]+` is anything but classname valid characters.
         *
         * TODO: Run this without the classname characters, log everytime a replacement is made across all test cases, add those to the test assertions, ensure this is always correct.
         */
        $contents = preg_replace(
            '/([^a-zA-Z0-9_\x7f-\xff\\\\][\'"])(' . preg_quote($originalSymbolString, '/') . ')([\'"][^a-zA-Z0-9_\x7f-\xff\\\\])/',
            '$1' . preg_quote($replacementSymblString, '/') . '$3',
            $contents
        );

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
        $globalClassesInterfacesTraits = $discoveredSymbols->getGlobalClassesInterfacesTraits();
        if (empty($globalClassesInterfacesTraits)) {
            return [];
        }

        $nodeFinder = new NodeFinder();
        $positions = [];

        // Replace \Classname (fully qualified) references in any namespace context.
        $fqNodes = $nodeFinder->find($ast, function (Node $node) use ($discoveredSymbols, &$positions) {
            if ($node->getAttribute('comments')) {
                /** @var \PhpParser\Comment\Doc $comment */
                $comment = $node->getAttribute('comments')[0];
                $positions = array_merge(
                    $positions,
                    $this->findGlobalSymbolsPositionsInComment($comment, $discoveredSymbols)
                );
            }
            if (!( $node instanceof Name\FullyQualified )) {
                return false;
            }
            return $this->hasGlobalSymbolForNode($node, $discoveredSymbols);
        });

        foreach ($fqNodes as $node) {
            $positions[] = [
                'start' => $node->getStartFilePos(),
                'end' => $node->getEndFilePos() + 1,
                'replacement' => '\\' . $this->getReplacementStringForNode($node, $discoveredSymbols),
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
                     && $discoveredSymbols->getClass($useItem->name->toString())
                ) {
                    $symbol = $discoveredSymbols->getClass($useItem->name->toString());
                    if ($symbol->isDoRename()) {
                        $aliasText = $symbol->getReplacement() . ' as ' . $useItem->name->toString();
                        $positions[] = [
                            'start' => $useItem->name->getStartFilePos(),
                            'end' => $useItem->name->getEndFilePos() + 1,
                            'replacement' => $aliasText,
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

        $classLike = $nodeFinder->find($globalStmts, function (Node $node) use ($discoveredSymbols) {
            return ($node instanceof \PhpParser\Node\Stmt\Class_
                || $node instanceof \PhpParser\Node\Stmt\Interface_
                || $node instanceof \PhpParser\Node\Stmt\Trait_
                || $node instanceof \PhpParser\Node\Stmt\Enum_)
                && isset($node->name)
                && $node->name instanceof \PhpParser\Node\Identifier
                && (
                       $discoveredSymbols->getClass($node->name->name)
                       || $discoveredSymbols->getInterface($node->name->name)
                       || $discoveredSymbols->getTrait($node->name->name)
                   );
        });
        foreach ($classLike as $node) {
            $positions[] = [
                'start' => $node->name->getStartFilePos(),
                'end' => $node->name->getEndFilePos() + 1,
                'replacement' => $this->getReplacementStringForNode($node, $discoveredSymbols),
            ];
        }

        $unqualifiedNameNodes = $nodeFinder->find($globalStmts, function (Node $node) use ($discoveredSymbols) {
            return $node instanceof Name
                && !($node instanceof Name\FullyQualified)
                   && $this->hasGlobalSymbolForNode($node, $discoveredSymbols);
        });
        foreach ($unqualifiedNameNodes as $node) {
            $positions[] = [
                'start' => $node->getStartFilePos(),
                'end' => $node->getEndFilePos() + 1,
                'replacement' => $this->getReplacementStringForNode($node, $discoveredSymbols)
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
            return $globalSymbol->getReplacement();
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
    protected function replaceConstants(string $contents, array $originalConstants, string $prefix): string
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
        $replacementFunctionString = $functionSymbol->getReplacement();

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
     * @param NamespaceSymbol[] $discoveredNamespaceSymbols
     */
    protected function prepareRelativeNamespaces(string $phpFileContent, array $discoveredNamespaceSymbols): string
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $ast = $parser->parse($phpFileContent);
        } catch (\PhpParser\Error $e) {
            $this->logger->warning("Skipping ::prepareRelativeNamespaces() in file due to parse error: " . $e->getMessage());
            return $phpFileContent;
        }

        $traverser = new NodeTraverser();
        $visitor = new class($discoveredNamespaceSymbols) extends \PhpParser\NodeVisitorAbstract {

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
                    $nameNodes = array_merge($nameNodes, $node->traits);
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
                            $nameNode->name = '\\' . $nameNode->name;
                            $this->countChanges++;
                        } else {
                            foreach ($this->using as $namespaceBase) {
                                if (in_array($namespaceBase . '\\' . $namespace, $this->discoveredNamespaces)) {
                                    $nameNode->name = '\\' . $namespaceBase . '\\' . $nameNode->name;
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
        };
        $traverser->addVisitor($visitor);

        $modifiedStmts = $traverser->traverse($ast);

        if ($visitor->countChanges === 0) {
            return $phpFileContent;
        }

        $updatedContent = (new Standard())->prettyPrintFile($modifiedStmts);

        $updatedContent = str_replace('namespace \\', 'namespace ', $updatedContent);
        $updatedContent = str_replace('use \\\\', 'use \\', $updatedContent);

        return $updatedContent;
    }
}
