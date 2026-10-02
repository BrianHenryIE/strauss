<?php
/**
 * The purpose of this class is only to find changes that should be made.
 * i.e. classes and namespaces to change.
 * Those recorded are updated in a later step.
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\FileSymbolScannerConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
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
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Const_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
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

    protected ?Parser $parser = null;
    protected ?NodeFinder $nodeFinder = null;

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
            $this->find(
                /**
                 * "one unreadable file cancels scanning of all remaining files with no per-file error handling."
                 * I think this is desirable, since we just ran the file list a moment ago, if something is unreadable, that's a show stopper.
                 */
                $this->filesystem->read($file->getSourcePath()),
                $file,
                $file instanceof FileWithDependency ? $file->getDependency() : null
            );
        }

        return $this->discoveredSymbols;
    }

    protected function find(string $contents, FileBase $file, ?ComposerPackage $package = null): void
    {
        try {
            $ast = $this->getParser()->parse($this->prepareForParsing($contents));
        } catch (Error $e) {
            // E.g. template files with placeholders: `namespace %g_namespace%\AdminMenus;`.
            $this->logger->warning('Failed to parse file {filePath} with error: {errorMessage}', [
                'filePath' => $file->getSourcePath(),
                'errorMessage' => $e->getMessage(),
            ]);
            return;
        }

        if (is_null($ast)) {
            return;
        }

        /**
         * Sets `namespacedName` on class-like, function and constant declarations, and a `resolvedName` attribute
         * on the names in `extends` and `implements`. The nodes themselves are not replaced, so the AST is the same
         * as {@see Prefixer} would produce by parsing the file itself.
         */
        $traverser = new NodeTraverser(new NameResolver(null, ['replaceNodes' => false]));
        $traverser->traverse($ast);

        /**
         * {@see Prefixer} strips anything before the first `<?` and prepends `<?php` when absent, so its AST positions
         * are only comparable to ours when the file begins with the PHP open tag.
         */
        if ($file instanceof File && 0 === strpos($contents, '<?')) {
            $file->setParsedAst($ast);
        }

        $namespaceNodes = array_filter($ast, fn(Node $node): bool => $node instanceof Namespace_);

        if (empty($namespaceNodes)) {
            $this->findInNamespace('\\', $ast, $file, $package);
            return;
        }

        /** @var Namespace_ $namespaceNode */
        foreach ($namespaceNodes as $namespaceNode) {
            $namespaceName = is_null($namespaceNode->name) ? '\\' : $namespaceNode->name->toString();
            $this->findInNamespace($namespaceName, $namespaceNode->stmts, $file, $package);
        }
    }

    /**
     * Mirror {@see Prefixer::replaceInString()}: discard anything before the first `<?`, and prepend `<?php` when
     * there is no open tag at all, so the scanner and the prefixer parse the same code.
     */
    protected function prepareForParsing(string $contents): string
    {
        $openTagPosition = strpos($contents, '<?');

        if (false === $openTagPosition) {
            return "<?php\n" . $contents;
        }

        return substr($contents, $openTagPosition);
    }

    /**
     * Record the namespace and every symbol declared within its statements, at any depth, e.g. a function declared
     * inside `if (!function_exists(...))`.
     *
     * @param string $namespaceName `\` for the global namespace.
     * @param Node[] $stmts
     */
    protected function findInNamespace(string $namespaceName, array $stmts, FileBase $file, ?ComposerPackage $package = null): void
    {
        $namespaceSymbol = $this->addDiscoveredNamespaceChange($namespaceName, $file, $package);

        /** @var Class_ $classNode */
        foreach ($this->getNodeFinder()->findInstanceOf($stmts, Class_::class) as $classNode) {
            if ($classNode->isAnonymous() || is_null($classNode->namespacedName)) {
                continue;
            }
            $classSymbol = $this->addDiscoveredClassChange(
                $classNode->namespacedName->toString(),
                $classNode->isAbstract(),
                $file,
                is_null($classNode->extends) ? null : $this->resolveName($classNode->extends),
                $namespaceSymbol,
                array_map([$this, 'resolveName'], $classNode->implements)
            );
            if ($classSymbol) {
                $classSymbol->setDoRename($file->isDoPrefix());
            }
        }

        /** @var Function_ $functionNode */
        foreach ($this->getNodeFinder()->findInstanceOf($stmts, Function_::class) as $functionNode) {
            if (is_null($functionNode->namespacedName)) {
                continue;
            }
            $functionName = $functionNode->namespacedName->toString();
            if ($this->isBuiltInSymbol($functionName)) {
                continue;
            }
            $functionSymbol = $this->discoveredSymbols->getFunction($functionName);
            if (is_null($functionSymbol)) {
                $functionSymbol = new FunctionSymbol($functionName, $file, $namespaceSymbol, $package);
                $this->add($functionSymbol);
            }
            $functionSymbol->addSourceFile($file);
            $functionSymbol->setDoRename($file->isDoPrefix());
        }

        foreach ($this->findConstantNames($stmts) as $constantName) {
            $constantSymbol = $this->discoveredSymbols->getConst($constantName);
            if (is_null($constantSymbol)) {
                $constantSymbol = new ConstantSymbol($constantName, $file, $namespaceSymbol);
                $this->add($constantSymbol, $file);
            }
            $constantSymbol->addSourceFile($file);
            $constantSymbol->setDoRename($file->isDoPrefix());
        }

        /** @var Interface_ $interfaceNode */
        foreach ($this->getNodeFinder()->findInstanceOf($stmts, Interface_::class) as $interfaceNode) {
            if (is_null($interfaceNode->namespacedName)) {
                continue;
            }
            $interfaceName = $interfaceNode->namespacedName->toString();
            $interfaceSymbol = $this->discoveredSymbols->getInterface($interfaceName);
            if (is_null($interfaceSymbol)) {
                $interfaceSymbol = new InterfaceSymbol($interfaceName, $file, $namespaceSymbol);
                $this->add($interfaceSymbol);
            }
            $interfaceSymbol->addSourceFile($file);
            $interfaceSymbol->setDoRename($file->isDoPrefix());
        }

        /** @var Trait_ $traitNode */
        foreach ($this->getNodeFinder()->findInstanceOf($stmts, Trait_::class) as $traitNode) {
            if (is_null($traitNode->namespacedName)) {
                continue;
            }
            $traitName = $traitNode->namespacedName->toString();
            $traitSymbol = $this->discoveredSymbols->getTrait($traitName);
            if (is_null($traitSymbol)) {
                $traitSymbol = new TraitSymbol($traitName, $file, $namespaceSymbol);
                $this->add($traitSymbol);
            }
            $traitSymbol->addSourceFile($file);
            $traitSymbol->setDoRename($file->isDoPrefix());
        }

        /** @var Enum_ $enumNode */
        foreach ($this->getNodeFinder()->findInstanceOf($stmts, Enum_::class) as $enumNode) {
            if (is_null($enumNode->namespacedName)) {
                continue;
            }
            $enumName = $enumNode->namespacedName->toString();
            if ($this->isBuiltInSymbol($enumName)) {
                continue;
            }
            $enumSymbol = $this->discoveredSymbols->getEnum($enumName);
            if (is_null($enumSymbol)) {
                $backingType = $enumNode->scalarType instanceof Node\Identifier ? $enumNode->scalarType->name : null;
                $enumSymbol = new EnumSymbol(
                    $enumName,
                    $file,
                    $namespaceSymbol,
                    $backingType,
                    array_map([$this, 'resolveName'], $enumNode->implements)
                );
                $this->add($enumSymbol, $file);
            }
            $enumSymbol->addSourceFile($file);
            $enumSymbol->setDoRename($file->isDoPrefix());
        }
    }

    /**
     * Constants declared with `const NAME = ...;` (class constants are `ClassConst`, not `Const_`) and with
     * `define('NAME', ...)`.
     *
     * The names are as written in the source; {@see ConstantSymbol} prefixes the namespace where they are unqualified.
     *
     * @param Node[] $stmts
     * @return string[]
     */
    protected function findConstantNames(array $stmts): array
    {
        $constantNames = [];

        $constantNodes = $this->getNodeFinder()->find(
            $stmts,
            fn(Node $node): bool => $node instanceof Const_ || $this->isDefineCall($node)
        );

        foreach ($constantNodes as $constantNode) {
            if ($constantNode instanceof Const_) {
                foreach ($constantNode->consts as $const) {
                    $constantNames[] = $const->name->toString();
                }
            } elseif ($constantNode instanceof FuncCall) {
                $firstArg = $constantNode->args[0] ?? null;
                if ($firstArg instanceof Node\Arg && $firstArg->value instanceof String_) {
                    $constantNames[] = $firstArg->value->value;
                }
            }
        }

        return array_filter($constantNames, fn(string $name): bool => '' !== trim($name, '\\'));
    }

    protected function isDefineCall(Node $node): bool
    {
        return $node instanceof FuncCall
            && $node->name instanceof Name
            && 'define' === strtolower($node->name->toString());
    }

    /**
     * The fully qualified name, without leading `\`, of a class name in `extends` or `implements`.
     */
    protected function resolveName(Name $name): string
    {
        $resolvedName = $name->getAttribute('resolvedName');

        return $resolvedName instanceof Name ? $resolvedName->toString() : $name->toString();
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

    protected function getParser(): Parser
    {
        return $this->parser ??= (new ParserFactory())->createForNewestSupportedVersion();
    }

    protected function getNodeFinder(): NodeFinder
    {
        return $this->nodeFinder ??= new NodeFinder();
    }
}
