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
use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\InterfaceSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use BrianHenryIE\Strauss\Types\TraitSymbol;
use League\Flysystem\FilesystemException;
use BrianHenryIE\SimplePhpParser\Parsers\Helper\ParserContainer;
use BrianHenryIE\SimplePhpParser\Parsers\Helper\ParserErrorHandler;
use BrianHenryIE\SimplePhpParser\Parsers\Helper\Utils;
use PhpParser\Node;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use BrianHenryIE\SimplePhpParser\Model\PHPClass;
use BrianHenryIE\SimplePhpParser\Model\PHPConst;
use BrianHenryIE\SimplePhpParser\Model\PHPFunction;
use BrianHenryIE\SimplePhpParser\Parsers\PhpCodeParser;
use BrianHenryIE\SimplePhpParser\Parsers\Visitors\ASTVisitor;

class FileSymbolScanner
{
    use LoggerAwareTrait;

    /**
     * @var array<class-string<DiscoveredSymbol>,string>
     */
    private const SYMBOL_LOG_TYPES = [
        ClassSymbol::class => 'class',
        ConstantSymbol::class => 'constant',
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
    protected ?Standard $prettyPrinter = null;

    private ?string $namespaceTokenCacheContents = null;

    /**
     * @var array<int, array<int, mixed>|string>|null
     */
    private ?array $namespaceTokenCacheTokens = null;

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
        $this->discoveredSymbols = $discoveredSymbols;

        $this->config = $config;

        $this->filesystem = $filesystem;
        $this->logger = $logger ?? new NullLogger();
    }


    protected function add(DiscoveredSymbol $symbol): void
    {
        $this->discoveredSymbols->add($symbol);

        $isAlreadyLogged = isset($this->loggedSymbols[$symbol->getOriginalSymbol()]);
        $level = $isAlreadyLogged ? 'debug' : 'info';
        $newText = $isAlreadyLogged ? '' : 'new ';

        $this->loggedSymbols[$symbol->getOriginalSymbol()] = true;
        $symbolType = self::SYMBOL_LOG_TYPES[get_class($symbol)] ?? 'symbol';

        $this->logger->log(
            $level,
            sprintf(
                "Found %s%s:::%s",
                $newText,
                $symbolType,
                $symbol->getOriginalSymbol()
            )
        );
    }

    /**
     * @throws FilesystemException
     */
    public function findInFiles(DiscoveredFiles $files): DiscoveredSymbols
    {
        $packagesToPrefixLookup = array_fill_keys(array_keys($this->config->getPackagesToPrefix()), true);
        $projectDirectory = $this->config->getProjectDirectory();

        foreach ($files->getFiles() as $file) {
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
                $this->filesystem->read($file->getSourcePath()),
                $file,
                $file instanceof FileWithDependency ? $file->getDependency() : null
            );
        }

        return $this->discoveredSymbols;
    }

    protected function find(string $contents, FileBase $file, ?ComposerPackage $package = null): void
    {
        $namespaces = $this->splitByNamespace($contents);
        PhpCodeParser::$classExistsAutoload = false;

        foreach ($namespaces as $namespaceName => $contents) {
            $phpCode = $this->parsePhpCode($contents);

            $this->addDiscoveredNamespaceChange($namespaceName, $file, $package);

            /** @var PHPClass[] $phpClasses */
            $phpClasses = $phpCode->getClasses();
            foreach ($phpClasses as $fqdnClassname => $class) {
                // Skip classes defined in other files.
                // I tried to use the $class->file property but it was autoloading from Strauss so incorrectly setting
                // the path, different to the file being scanned.
                if (false !== strpos($contents, "use {$fqdnClassname};")) {
                    continue;
                }

                $isAbstract = (bool) $class->is_abstract;
                $extends     = $class->parentClass;
                $interfaces  = $class->interfaces;
                $this->addDiscoveredClassChange($fqdnClassname, $isAbstract, $file, $namespaceName, $extends, $interfaces);
            }

            /** @var PHPFunction[] $phpFunctions */
            $phpFunctions = $phpCode->getFunctions();
            foreach ($phpFunctions as $functionName => $function) {
                if ($this->isBuiltInSymbol($functionName)) {
                    continue;
                }
                $functionSymbol = new FunctionSymbol($functionName, $file, $namespaceName, $package);
                $this->add($functionSymbol);
            }

            /** @var PHPConst[] $phpConstants */
            $phpConstants = $phpCode->getConstants();
            foreach ($phpConstants as $constantName => $constant) {
                $constantSymbol = new ConstantSymbol($constantName, $file, $namespaceName, $package);
                $this->add($constantSymbol);
            }

            $phpInterfaces = $phpCode->getInterfaces();
            foreach ($phpInterfaces as $interfaceName => $interface) {
                $interfaceSymbol = new InterfaceSymbol($interfaceName, $file, $namespaceName, $package);
                $this->add($interfaceSymbol);
            }

            $phpTraits =  $phpCode->getTraits();
            foreach ($phpTraits as $traitName => $trait) {
                $traitSymbol = new TraitSymbol($traitName, $file, $namespaceName, $package);
                $this->add($traitSymbol);
            }
        }
    }

    /**
     * @param string $contents
     * @return array<string,string>
     */
    protected function splitByNamespace(string $contents):array
    {
        $this->namespaceTokenCacheContents = $contents;
        $this->namespaceTokenCacheTokens = token_get_all($contents);

        try {
            $namespaceDeclarations = $this->getNamespaceDeclarations($contents);
            $hasMalformedNamespaceDeclaration = count($namespaceDeclarations) === 0
                && $this->hasMalformedNamespaceDeclaration($contents);
        } finally {
            $this->namespaceTokenCacheContents = null;
            $this->namespaceTokenCacheTokens = null;
        }

        if (count($namespaceDeclarations) === 0) {
            if ($hasMalformedNamespaceDeclaration) {
                return [];
            }

            return ['\\' => $this->ensurePhpOpeningTag($contents)];
        }

        if (count($namespaceDeclarations) === 1) {
            $namespaceName = $namespaceDeclarations[0] ?? '\\';
            $namespaceName = empty($namespaceName) ? '\\' : $namespaceName;
            return [$namespaceName => $contents];
        }

        $result = [];
        try {
            $ast = $this->getParser()->parse(trim($contents)) ?? [];
        } catch (\PhpParser\Error $e) {
            return [];
        }

        foreach ($ast as $rootNode) {
            if ($rootNode instanceof Node\Stmt\Namespace_) {
                if (is_null($rootNode->name)) {
                    if (count($ast) === 1) {
                        $result['\\'] = $contents;
                    } else {
                        $result['\\'] = $this->getPrettyPrinter()->prettyPrintFile($rootNode->stmts);
                    }
                } else {
                    $namespaceName = $rootNode->name->name;
                    if (count($ast) === 1) {
                        $result[$namespaceName] = $contents;
                    } else {
                        // This was failing for `phpoffice/phpspreadsheet/src/PhpSpreadsheet/Writer/Xlsx/FunctionPrefix.php`
                        $result[$namespaceName] = '<?php' . PHP_EOL . PHP_EOL . 'namespace ' . $namespaceName . ';' . PHP_EOL . PHP_EOL . $this->getPrettyPrinter()->prettyPrint($rootNode->stmts);
                    }
                }
            }
        }

        // TODO: is this necessary?
        if (empty($result)) {
            $result['\\'] = $this->ensurePhpOpeningTag($contents);
        }

        return $result;
    }

    private function ensurePhpOpeningTag(string $contents): string
    {
        $trimmedContents = ltrim($contents);
        if (0 === strpos($trimmedContents, '<?')) {
            return $contents;
        }

        return '<?php' . PHP_EOL . PHP_EOL . $contents;
    }

    /**
     * @param string $fqdnClassname
     * @param bool $isAbstract
     * @param FileBase $file
     * @param string $namespaceName
     * @param ?string $extends
     * @param string[] $interfaces
     */
    protected function addDiscoveredClassChange(
        string $fqdnClassname,
        bool $isAbstract,
        FileBase $file,
        string $namespaceName,
        ?string $extends,
        array $interfaces
    ): void {
        // TODO: This should be included but marked not to prefix.
        if ($this->isBuiltInSymbol($fqdnClassname)) {
            return;
        }

        $classSymbol = new ClassSymbol($fqdnClassname, $file, $isAbstract, $namespaceName, $extends, $interfaces);
        $this->add($classSymbol);
    }

    protected function addDiscoveredNamespaceChange(string $namespace, FileBase $file, ?ComposerPackage $package = null): void
    {
        $namespaceObj = $this->discoveredSymbols->getNamespace($namespace);
        if ($namespaceObj) {
            $namespaceObj->addSourceFile($file);
            $file->addDiscoveredSymbol($namespaceObj);
            return;
        } else {
            $namespaceObj = new NamespaceSymbol($namespace, $file, '\\', $package);
        }

        $this->add($namespaceObj);
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

    protected function getPrettyPrinter(): Standard
    {
        return $this->prettyPrinter ??= new Standard();
    }

    protected function parsePhpCode(string $contents): ParserContainer
    {
        $parserContainer = new ParserContainer();
        $visitor = new ASTVisitor($parserContainer);

        $result = PhpCodeParser::process($contents, null, $parserContainer, $visitor);
        if (!$result instanceof ParserContainer && $result instanceof ParserErrorHandler) {
            return $parserContainer;
        }

        $interfaces = $parserContainer->getInterfaces();
        foreach ($interfaces as &$interface) {
            $interface->parentInterfaces = $visitor->combineParentInterfaces($interface);
        }
        unset($interface);

        $classes = &$parserContainer->getClassesByReference();
        foreach ($classes as &$class) {
            $class->interfaces = Utils::flattenArray(
                $visitor->combineImplementedInterfaces($class),
                false
            );
        }
        unset($class);

        return $parserContainer;
    }

    /**
     * Collect declared namespaces in source order. Namespace operators (namespace\foo) are ignored.
     *
     * @return array<int,string|null> Null indicates the explicit global namespace.
     */
    protected function getNamespaceDeclarations(string $contents): array
    {
        $tokens = $this->getNamespaceTokens($contents);

        $declarations = [];
        $tokenCount = count($tokens);
        for ($i = 0; $i < $tokenCount; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_NAMESPACE) {
                continue;
            }

            $nextIndex = $this->nextSignificantTokenIndex($tokens, $i + 1);
            if (is_null($nextIndex)) {
                continue;
            }

            $next = $tokens[$nextIndex];
            if (is_string($next) && ($next === ';' || $next === '{')) {
                $declarations[] = null;
                continue;
            }

            $namespaceName = '';
            while (!is_null($nextIndex)) {
                $current = $tokens[$nextIndex];

                if (is_array($current) && $this->isNamespaceNameToken($current[0])) {
                    $namespaceName .= $current[1];
                    $nextIndex = $this->nextSignificantTokenIndex($tokens, $nextIndex + 1);
                    continue;
                }

                if (is_string($current) && $current === '\\') {
                    $namespaceName .= '\\';
                    $nextIndex = $this->nextSignificantTokenIndex($tokens, $nextIndex + 1);
                    continue;
                }

                if (is_string($current) && ($current === ';' || $current === '{')) {
                    if ($namespaceName !== '') {
                        $declarations[] = trim($namespaceName, '\\');
                    }
                }
                break;
            }
        }

        return $declarations;
    }

    protected function hasMalformedNamespaceDeclaration(string $contents): bool
    {
        $tokens = $this->getNamespaceTokens($contents);
        $tokenCount = count($tokens);
        for ($i = 0; $i < $tokenCount; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_NAMESPACE) {
                continue;
            }

            $nextIndex = $this->nextSignificantTokenIndex($tokens, $i + 1);
            if (is_null($nextIndex)) {
                continue;
            }

            $next = $tokens[$nextIndex];
            if (is_string($next) && ($next === ';' || $next === '{')) {
                continue;
            }

            if (is_array($next) && $this->isNamespaceNameToken($next[0])) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @return array<int, array<int, mixed>|string>
     */
    private function getNamespaceTokens(string $contents): array
    {
        if ($this->namespaceTokenCacheTokens !== null && $this->namespaceTokenCacheContents === $contents) {
            return $this->namespaceTokenCacheTokens;
        }

        return token_get_all($contents);
    }

    /**
     * @param array<int, array<int, mixed>|string> $tokens
     */
    protected function nextSignificantTokenIndex(array $tokens, int $start): ?int
    {
        $tokenCount = count($tokens);
        for ($i = $start; $i < $tokenCount; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    protected function isNamespaceNameToken(int $tokenType): bool
    {
        if ($tokenType === T_STRING || $tokenType === T_NS_SEPARATOR) {
            return true;
        }

        return
            (defined('T_NAME_QUALIFIED') && $tokenType === T_NAME_QUALIFIED)
            || (defined('T_NAME_FULLY_QUALIFIED') && $tokenType === T_NAME_FULLY_QUALIFIED)
            || (defined('T_NAME_RELATIVE') && $tokenType === T_NAME_RELATIVE);
    }
}
