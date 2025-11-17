<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\PrefixerConfigInterface;
use BrianHenryIE\Strauss\Files\File;
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
     * @param array<File> $files
     *
     * @throws FilesystemException
     * @throws FilesystemException
     */
    public function replaceInFiles(DiscoveredSymbols $discoveredSymbols, array $files): void
    {
        foreach ($files as $file) {
            if (!$file->isDoPrefix()) {
                continue;
            }

            if ($this->filesystem->directoryExists($file->getAbsoluteTargetPath())) {
                $this->logger->debug("is_dir() / nothing to do : {$file->getAbsoluteTargetPath()}");
                continue;
            }

            if (!$file->isPhpFile()) {
                continue;
            }

            if (!$this->filesystem->fileExists($file->getAbsoluteTargetPath())) {
                $this->logger->warning("Expected file does not exist: {$file->getAbsoluteTargetPath()}");
                continue;
            }

            $relativeFilePath = $this->filesystem->getRelativePath(dirname($this->config->getTargetDirectory()), $file->getAbsoluteTargetPath());

            $this->logger->debug("Updating contents of file: {$relativeFilePath}");

            /**
             * Throws an exception, but unlikely to happen.
             */
            $contents = $this->filesystem->read($file->getAbsoluteTargetPath());

            $updatedContents = $this->replaceInString($discoveredSymbols, $contents);

            if ($updatedContents !== $contents) {
                // TODO: diff here and debug log.
                $file->setDidUpdate();
                $this->filesystem->write($file->getAbsoluteTargetPath(), $updatedContents);
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
            $relativeFilePath = $this->filesystem->getRelativePath(dirname($this->config->getTargetDirectory()), $fileAbsolutePath);

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
        $constants = $discoveredSymbols->getDiscoveredConstants($this->config->getConstantsPrefix());
//        $constants = $discoveredSymbols->getDiscoveredConstantChanges($this->config->getConstantsPrefix());
        $classes = $discoveredSymbols->getGlobalClassChanges();
        $functions = $discoveredSymbols->getDiscoveredFunctionChanges();

        $contents = $this->prepareRelativeNamespaces($contents, $namespacesChanges);

        if ($classmapPrefix) {
            foreach ($classes as $classSymbol) {
                $contents = $this->replaceClassname($contents, $classSymbol->getOriginalSymbol(), $classmapPrefix);
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
        $ast = $parser->parse($contents);

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
            |implements\s+
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
     *
     * @throws Exception
     */
    public function replaceClassname(string $contents, string $originalClassname, string $classnamePrefix): string
    {
        $searchClassname = preg_quote($originalClassname, '/');

        // This could be more specific if we could enumerate all preceding and proceeding words ("new", "("...).
        $pattern = '
			/											# Start the pattern
				(^\s*namespace|\r\n\s*namespace)\s+[a-zA-Z0-9_\x7f-\xff\\\\]+\s*{(.*?)(namespace|\z) 
														# Look for a preceding namespace declaration, up until a 
														# potential second namespace declaration.
				|										# if found, match that much before continuing the search on
								    		        	# the remainder of the string.
                (^\s*namespace|\r\n\s*namespace)\s+[a-zA-Z0-9_\x7f-\xff\\\\]+\s*;(.*) # Skip lines just declaring the namespace.
                |		        	
				([^a-zA-Z0-9_\x7f-\xff\$\\\])(' . $searchClassname . ')([^a-zA-Z0-9_\x7f-\xff\\\]) # outside a namespace the class will not be prefixed with a slash
				
			/xsm'; //                                    # x: ignore whitespace in regex.  s dot matches newline, m: ^ and $ match start and end of line

        $replacingFunction = function ($matches) use ($originalClassname, $classnamePrefix) {

            // If we're inside a namespace other than the global namespace:
            if (1 === preg_match('/\s*namespace\s+[a-zA-Z0-9_\x7f-\xff\\\\]+[;{\s\n]{1}.*/', $matches[0])) {
                return $this->replaceGlobalClassInsideNamedNamespace(
                    $matches[0],
                    $originalClassname,
                    $classnamePrefix
                );
            } else {
                $newContents = '';
                foreach ($matches as $index => $captured) {
                    if (0 === $index) {
                        continue;
                    }

                    if ($captured == $originalClassname) {
                        $newContents .= $classnamePrefix;
                    }

                    $newContents .= $captured;
                }
                return $newContents;
            }
//            return $matches[1] . $matches[2] . $matches[3] . $classnamePrefix . $originalClassname . $matches[5];
        };

        $result = preg_replace_callback($pattern, $replacingFunction, $contents);

        if (is_null($result)) {
            throw new Exception('preg_replace_callback returned null');
        }

        $this->checkPregError();

        return $result;
    }

    /**
     * Pass in a string and look for \Classname instances.
     *
     * @param string $contents
     * @param string $originalClassname
     * @param string $classnamePrefix
     * @return string
     */
    protected function replaceGlobalClassInsideNamedNamespace(
        string $contents,
        string $originalClassname,
        string $classnamePrefix
    ): string {
        $replacement = $classnamePrefix . $originalClassname;

        // use Prefixed_Class as Class;
        $usePattern = '/
			(\s*use\s+)
			(' . $originalClassname . ')   # Followed by the classname
			\s*;
			/x'; //                    # x: ignore whitespace in regex.

        $contents = preg_replace_callback(
            $usePattern,
            function ($matches) use ($replacement) {
                return $matches[1] . $replacement . ' as ' . $matches[2] . ';';
            },
            $contents
        );

        $this->checkPregError();

        $bodyPattern =
            '/([^a-zA-Z0-9_\x7f-\xff]  # Not a class character
			\\\)                       # Followed by a backslash to indicate global namespace
			(' . $originalClassname . ')   # Followed by the classname
			([^\\\;]{1})               # Not a backslash or semicolon which might indicate a namespace
			/x'; //                    # x: ignore whitespace in regex.

        $result = preg_replace_callback(
            $bodyPattern,
            function ($matches) use ($replacement) {
                return $matches[1] . $replacement . $matches[3];
            },
            $contents
        ) ?? $contents; // TODO: If this happens, it should raise an exception.

        $this->checkPregError();

        return $result;
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
        $ast = $parser->parse($contents);

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

        $ast = $parser->parse($phpFileContent);

        $traverser = new NodeTraverser();
        $visitor = new class($discoveredNamespaceSymbols) extends \PhpParser\NodeVisitorAbstract {

            public int $countChanges = 0;
            protected array $discoveredNamespaces;

            protected Node $lastNode;

            /**
             * The list of `use Namespace\Subns;` statements in the file.
             *
             * @var string[]
             */
            protected array $using = [];

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
                        $use->name->name = ltrim($use->name->name, '\\');
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
}
