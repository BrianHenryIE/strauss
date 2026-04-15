<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\PrefixerConfigInterface;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Helpers\NamespaceSort;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Exception;
use League\Flysystem\FilesystemException;
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

            $relativeFilePath = $this->filesystem->getRelativePath(dirname($this->config->getAbsoluteTargetDirectory()), $file->getTargetAbsolutePath());

            $this->logger->debug("Updating contents of file: {$relativeFilePath}");

            /**
             * Throws an exception, but unlikely to happen.
             */
            $contents = $this->filesystem->read($file->getTargetAbsolutePath());

            $updatedContents = $this->replaceInString($discoveredSymbols, $contents);

            if ($updatedContents !== $contents) {
                // TODO: diff here and debug log.
                $file->setDidUpdate();
                $this->filesystem->write($file->getTargetAbsolutePath(), $updatedContents);
                $this->logger->info("Updated contents of file: {$relativeFilePath}");
            } else {
                $this->logger->debug("No changes to file: {$relativeFilePath}");
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
        $classmapPrefix = $this->config->getClassmapPrefix();

        $namespacesChanges = $discoveredSymbols->getDiscoveredNamespaceChanges($this->config->getNamespacePrefix());
        $constants = $discoveredSymbols->getDiscoveredConstantChanges($this->config->getConstantsPrefix());
        $classes = $discoveredSymbols->getGlobalClassChanges();
        $functions = $discoveredSymbols->getDiscoveredFunctionChanges();

        $contents = $this->prepareRelativeNamespaces($contents, $namespacesChanges);

        if ($classmapPrefix) {
            foreach ($classes as $classSymbol) {
                $contents = $this->replaceClassname($contents, $classSymbol->getOriginalSymbolStripPrefix($classmapPrefix), $classmapPrefix);
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
     * @throws Exception
     */
    public function replaceNamespace(string $contents, string $originalNamespace, string $replacement): string
    {

        $searchNamespace = '\\' . rtrim($originalNamespace, '\\') . '\\';
        $searchNamespace = str_replace('\\\\', '\\', $searchNamespace);
        $searchNamespace = str_replace('\\', '\\\\{0,2}', $searchNamespace);

        $pattern = "
            /                              # Start the pattern
            (
            ^\s*                          # start of the string
            |\\n\s*                        # start of the line
            |(<?php\s+namespace|^\s*namespace|[\r\n]+\s*namespace)\s+                  # the namespace keyword
            |use\s+                        # the use keyword
            |use\s+function\s+			   # the use function syntax
            |new\s+
            |static\s+
            |\"                            # inside a string that does not contain spaces - needs work
            |'                             #   right now its just inside a string that doesnt start with a space
            |implements\s+\\\\             # when the interface being implemented is namespaced inline
            |extends\s+\\\\                    # when the class being extended is namespaced inline
            |return\s+
            |instanceof\s+                 # when checking the class type of an object in a conditional
            |\(\s*                         # inside a function declaration as the first parameters type
            |,\s*                          # inside a function declaration as a subsequent parameter type
            |\.\s*                         # as part of a concatenated string
            |=\s*                          # as the value being assigned to a variable
            |\*\s+@\w+\s*                  # In a comments param etc
            |&\s*                             # a static call as a second parameter of an if statement
            |\|\s*
            |!\s*                             # negating the result of a static call
            |=>\s*                            # as the value in an associative array
            |\[\s*                         # In a square array
            |\?\s*                         # In a ternary operator
            |:\s*                          # In a ternary operator
            |<                             # In a generic type declaration
            |\(string\)\s*                 # casting a namespaced class to a string
            )
            @?                             # Maybe preceded by the @ symbol for error suppression
            (?<searchNamespace>
            {$searchNamespace}             # followed by the namespace to replace
            )
            (?!:)                          # Not followed by : which would only be valid after a classname
            (
            \s*;                           # followed by a semicolon
            |\s*{                          # or an opening brace for multiple namespaces per file
            |\\\\{1,2}[a-zA-Z0-9_\x7f-\xff]{1,}         # or a classname no slashes
            |\s+as                         # or the keyword as
            |\"                            # or quotes
            |'                             # or single quote
            |:                             # or a colon to access a static
            |\\\\{
            |>                             # In a generic type declaration (end)
            )
            /Ux";                          // U: Non-greedy matching, x: ignore whitespace in pattern.

        $replacingFunction = function ($matches) use ($originalNamespace, $replacement) {
            $singleBackslash = '\\';
            $doubleBackslash = '\\\\';

            if (false !== strpos($matches['0'], $doubleBackslash)) {
                $originalNamespace = str_replace($singleBackslash, $doubleBackslash, $originalNamespace);
                $replacement = str_replace($singleBackslash, $doubleBackslash, $replacement);
            }

            return str_replace($originalNamespace, $replacement, $matches[0]);
        };

        $result = preg_replace_callback($pattern, $replacingFunction, $contents);

        $this->checkPregError();

        // For prefixed functions which do not begin with a backslash, add one.
        // I'm not certain this is a good idea.
        // @see https://github.com/BrianHenryIE/strauss/issues/65
        $functionReplacingPattern = '/\\\\?(' . preg_quote(ltrim($replacement, '\\'), '/') . '\\\\(?:[a-zA-Z0-9_\x7f-\xff]+\\\\)*[a-zA-Z0-9_\x7f-\xff]+\\()/';

        return preg_replace(
            $functionReplacingPattern,
            "\\\\$1",
            $result
        );
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
    public function replaceClassname(string $contents, string $originalClassname, string $classnamePrefix): string
    {
        $replacement = $classnamePrefix . $originalClassname;

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
            return $contents;
        }

        $nodeFinder = new NodeFinder();
        $positions = [];

        // Replace \Classname (fully qualified) references in any namespace context.
        $fqNames = $nodeFinder->find($ast, function (Node $node) use ($originalClassname) {
            return $node instanceof Name\FullyQualified
                && $node->toString() === $originalClassname;
        });
        foreach ($fqNames as $name) {
            $positions[] = [
                'start' => $name->getStartFilePos(),
                'end' => $name->getEndFilePos() + 1,
                'replacement' => '\\' . $replacement,
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
                    && $useItem->name->toString() === $originalClassname
                ) {
                    $aliasText = $useItem->alias === null ? ' as ' . $originalClassname : '';
                    $positions[] = [
                        'start' => $useItem->name->getStartFilePos(),
                        'end' => $useItem->name->getEndFilePos() + 1,
                        'replacement' => $replacement . $aliasText,
                    ];
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

        $classLike = $nodeFinder->find($globalStmts, function (Node $node) use ($originalClassname) {
            return ($node instanceof \PhpParser\Node\Stmt\Class_
                || $node instanceof \PhpParser\Node\Stmt\Interface_
                || $node instanceof \PhpParser\Node\Stmt\Trait_
                || $node instanceof \PhpParser\Node\Stmt\Enum_)
                && isset($node->name)
                && $node->name instanceof \PhpParser\Node\Identifier
                && $node->name->name === $originalClassname;
        });
        foreach ($classLike as $node) {
            $positions[] = [
                'start' => $node->name->getStartFilePos(),
                'end' => $node->name->getEndFilePos() + 1,
                'replacement' => $replacement,
            ];
        }

        $unqualifiedNames = $nodeFinder->find($globalStmts, function (Node $node) use ($originalClassname) {
            return $node instanceof Name
                && !($node instanceof Name\FullyQualified)
                && $node->toString() === $originalClassname;
        });
        foreach ($unqualifiedNames as $name) {
            $positions[] = [
                'start' => $name->getStartFilePos(),
                'end' => $name->getEndFilePos() + 1,
                'replacement' => $replacement,
            ];
        }

        // Handle \Classname references inside doc comment type annotations.
        $nodesWithDocComments = $nodeFinder->find($ast, function (Node $node) {
            return $node->getDocComment() !== null;
        });
        $searchStr = '\\' . $originalClassname;
        $searchLen = strlen($searchStr);
        foreach ($nodesWithDocComments as $node) {
            $docComment = $node->getDocComment();
            $commentText = $docComment->getText();
            $startFilePos = $docComment->getStartFilePos();
            $offset = 0;
            while (($pos = strpos($commentText, $searchStr, $offset)) !== false) {
                $nextPos = $pos + $searchLen;
                if ($nextPos >= strlen($commentText)
                    || !preg_match('/[a-zA-Z0-9_\x7f-\xff\\\\]/', $commentText[$nextPos])
                ) {
                    $positions[] = [
                        'start' => $startFilePos + $pos,
                        'end' => $startFilePos + $nextPos,
                        'replacement' => '\\' . $replacement,
                    ];
                }
                $offset = $pos + 1;
            }
        }

        if (empty($positions)) {
            return $contents;
        }

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

        /**
         * Replace classnames in strings, e.g. `is_a( $recurrence, 'CronExpression' )`.
         *
         * `[^a-zA-Z0-9_\x7f-\xff\\\\]+` is anything but classname valid characters.
         *
         * TODO: Run this without the classname characters, log everytime a replacement is made across all test cases, add those to the test assertions, ensure this is always correct.
         */
        $contents = preg_replace(
            '/([^a-zA-Z0-9_\x7f-\xff\\\\][\'"])(' . preg_quote($originalClassname, '/') . ')([\'"][^a-zA-Z0-9_\x7f-\xff\\\\])/',
            '$1' . preg_quote($replacement, '/') . '$3',
            $contents
        );

        return $contents;
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
