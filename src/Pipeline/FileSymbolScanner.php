<?php
/**
 * The purpose of this class is only to find changes that should be made.
 * i.e. classes and namespaces to change.
 * Those recorded are updated in a later step.
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Config\FileSymbolScannerConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Helpers\SimplePhpParser;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\InterfaceSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use BrianHenryIE\Strauss\Types\TraitSymbol;
use League\Flysystem\FilesystemReader;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use voku\SimplePhpParser\Model\PHPClass;
use voku\SimplePhpParser\Model\PHPConst;
use voku\SimplePhpParser\Model\PHPFunction;

class FileSymbolScanner
{
    use LoggerAwareTrait;

    /** @var string[]  */
    protected array $excludeNamespacesFromPrefixing = array();

    protected DiscoveredSymbols $discoveredSymbols;

    protected FilesystemReader $filesystem;

    protected FileSymbolScannerConfigInterface $config;

    /** @var string[] */
    protected array $builtIns = [];

    /**
     * @var string[]
     */
    protected array $loggedSymbols = [];

    /**
     * FileScanner constructor.
     */
    public function __construct(
        FileSymbolScannerConfigInterface $config,
        FilesystemReader $filesystem,
        ?LoggerInterface $logger = null
    ) {
        $this->discoveredSymbols = new DiscoveredSymbols();
        $this->excludeNamespacesFromPrefixing = $config->getExcludeNamespacesFromPrefixing();

        $this->config = $config;

        $this->filesystem = $filesystem;
        $this->logger = $logger ?? new NullLogger();
    }

    protected function add(DiscoveredSymbol $symbol): void
    {
        $this->discoveredSymbols->add($symbol);

        $level = in_array($symbol->getOriginalSymbol(), $this->loggedSymbols) ? 'debug' : 'info';
        $newText = in_array($symbol->getOriginalSymbol(), $this->loggedSymbols) ? '' : 'new ';
        $noNewText = in_array($symbol->getOriginalSymbol(), $this->loggedSymbols) ? '   ' : '';

        $this->loggedSymbols[] = $symbol->getOriginalSymbol();

        switch (get_class($symbol)) {
            case NamespaceSymbol::class:
                $this->logger->log($level, "Found {$newText}namespace:  {$noNewText}" . $symbol->getOriginalSymbol());
                break;
            case ConstantSymbol::class:
                $this->logger->log($level, "Found {$newText}constant:   {$noNewText}" . $symbol->getOriginalSymbol());
                break;
            case ClassSymbol::class:
                $this->logger->log($level, "Found {$newText}class.     {$noNewText}" . $symbol->getOriginalSymbol());
                break;
            case FunctionSymbol::class:
                $this->logger->log($level, "Found {$newText}function    {$noNewText}" . $symbol->getOriginalSymbol());
                break;
            default:
                $this->logger->log($level, "Found {$newText} " . get_class($symbol) . $noNewText . ' ' . $symbol->getOriginalSymbol());
        }
    }

    /**
     * @param DiscoveredFiles $files
     */
    public function findInFiles(DiscoveredFiles $files): DiscoveredSymbols
    {
        foreach ($files->getFiles() as $file) {
            if ($file instanceof FileWithDependency && !in_array($file->getDependency()->getPackageName(), array_keys($this->config->getPackagesToPrefix()))) {
                $file->setDoPrefix(false);
                continue;
            }

            if (!$file->isPhpFile()) {
                $file->setDoPrefix(false);
                $this->logger->debug('Skipping non-PHP file: ' . $file->getSourcePath());
                continue;
            }

            $this->logger->info('Scanning file:        ' . $file->getSourcePath());
            $this->find(
                $this->filesystem->read($file->getSourcePath()),
                $file
            );
        }

        return $this->discoveredSymbols;
    }

    protected function find(string $contents, File $file): void
    {
        $namespaces = $this->splitByNamespace($contents);

        foreach ($namespaces as $namespaceName => $contents) {
            $this->addDiscoveredNamespaceChange($namespaceName, $file);

            // Because we split the file by namespace, we need to add it back to avoid the case where `class A extends \A`.
            $contents = trim($contents);
            $contents = false === strpos($contents, '<?php') ? "<?php\n" . $contents : $contents;
            if ($namespaceName !== '\\') {
                $contents = str_replace('<?php', '<?php' . PHP_EOL . 'namespace ' . $namespaceName . ';' . PHP_EOL, $contents);
            }

            $phpCode = SimplePhpParser::getFromString($contents);

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
                if (in_array($functionName, $this->getBuiltIns())) {
                    continue;
                }
                $functionSymbol = new FunctionSymbol($functionName, $file, $namespaceName);
                $this->add($functionSymbol);
            }

            /** @var PHPConst $phpConstants */
            $phpConstants = $phpCode->getConstants();
            foreach ($phpConstants as $constantName => $constant) {
                $constantSymbol = new ConstantSymbol($constantName, $file, $namespaceName);
                $this->add($constantSymbol);
            }

            $phpInterfaces = $phpCode->getInterfaces();
            foreach ($phpInterfaces as $interfaceName => $interface) {
                $interfaceSymbol = new InterfaceSymbol($interfaceName, $file, $namespaceName);
                $this->add($interfaceSymbol);
            }

            $phpTraits =  $phpCode->getTraits();
            foreach ($phpTraits as $traitName => $trait) {
                $traitSymbol = new TraitSymbol($traitName, $file, $namespaceName);
                $this->add($traitSymbol);
            }
        }
    }

    protected function splitByNamespace(string $contents):array
    {
        $result = [];

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        $ast = $parser->parse(trim($contents));

        foreach ($ast as $rootNode) {
            if ($rootNode instanceof Node\Stmt\Namespace_) {
                if (is_null($rootNode->name)) {
                    $result['\\'] = (new Standard())->prettyPrintFile($rootNode->stmts);
                } else {
                    $result[$rootNode->name->name] = (new Standard())->prettyPrintFile($rootNode->stmts);
                }
            }
        }

        // TODO: is this necessary?
        if (empty($result)) {
            $result['\\'] = $contents;
        }

        return $result;
    }

    protected function addDiscoveredClassChange(
        string $fqdnClassname,
        bool $isAbstract,
        File $file,
        $namespaceName,
        $extends,
        array $interfaces
    ): void {
        // TODO: This should be included but marked not to prefix.
        if (in_array($fqdnClassname, $this->getBuiltIns())) {
            return;
        }

        $classSymbol = new ClassSymbol($fqdnClassname, $file, $isAbstract, $namespaceName, $extends, $interfaces);
        $this->add($classSymbol);
    }

    protected function addDiscoveredNamespaceChange(string $namespace, File $file): void
    {

        foreach ($this->excludeNamespacesFromPrefixing as $excludeNamespace) {
            if (0 === strpos($namespace, $excludeNamespace)) {
                // TODO: Log.
                return;
            }
        }

        $namespaceObj = $this->discoveredSymbols->getNamespace($namespace);
        if ($namespaceObj) {
            $namespaceObj->addSourceFile($file);
            $file->addDiscoveredSymbol($namespaceObj);
            return;
        } else {
            $namespaceObj = new NamespaceSymbol($namespace, $file);
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
    }
}
