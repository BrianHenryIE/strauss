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
use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use BrianHenryIE\SimplePhpParser\Model\PHPClass;
use BrianHenryIE\SimplePhpParser\Model\PHPConst;
use BrianHenryIE\SimplePhpParser\Model\PHPFunction;
use BrianHenryIE\SimplePhpParser\Parsers\PhpCodeParser;

class FileSymbolScanner
{
    use LoggerAwareTrait;

    protected DiscoveredSymbols $discoveredSymbols;

    protected FileSystem $filesystem;

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
        DiscoveredSymbols $discoveredSymbols,
        FileSystem $filesystem,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->discoveredSymbols = $discoveredSymbols;
        $this->filesystem = $filesystem;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @throws FilesystemException
     */
    public function findInFiles(DiscoveredFiles $files): DiscoveredSymbols
    {
        foreach ($files->getFiles() as $file) {
            if ($file instanceof FileWithDependency
                && !in_array($file->getDependency()->getPackageName(), array_keys($this->config->getPackagesToPrefix()))) {
                /**
                 * We will not prefix symbols found in this file because it is not in a default or listed package.
                 *
                 * TODO: Move this logic to {@see MarkSymbolsForRenaming}.
                 */
                $file->setDoPrefix(false);
            }

            $relativeFilePath = $this->filesystem->getRelativePath(
                $this->config->getProjectAbsolutePath(),
                $file->getSourcePath()
            );

            if (!$file->isPhpFile()) {
                $file->setDoPrefix(false);
                $this->logger->debug("Skipping non-PHP file:::". $relativeFilePath);
                continue;
            }

            $this->logger->info("Scanning file:::" . $relativeFilePath);
            $this->find(
                $this->filesystem->read($file->getSourcePath()),
                $file
            );
        }

        return $this->discoveredSymbols;
    }

    protected function find(string $contents, FileBase $file): void
    {
        $namespaces = $this->splitByNamespace($contents);

        foreach ($namespaces as $namespaceName => $contents) {
            $namespaceSymbol = $this->addDiscoveredNamespaceChange($namespaceName, $file);

            PhpCodeParser::$classExistsAutoload = false;
            $phpCode = PhpCodeParser::getFromString($contents);

            /** @var PHPClass[] $phpClasses */
            $phpClasses = $phpCode->getClasses();
            foreach ($phpClasses as $fqdnClassname => $class) {
                // Skip classes defined in other files.
                // I tried to use the $class->file property but it was autoloading from Strauss so incorrectly setting
                // the path, different to the file being scanned.
                // TODO this was causing false positives when found in comments.
//                if (false !== strpos($contents, "use {$fqdnClassname};")) {
//                    continue;
//                }

                $isAbstract = (bool) $class->is_abstract;
                $extends     = $class->parentClass;
                $interfaces  = $class->interfaces;
                $classSymbol = $this->addDiscoveredClassChange($fqdnClassname, $isAbstract, $file, $extends, $namespaceSymbol, $interfaces);
                if ($classSymbol) {
                    $classSymbol->setDoRename($file->isDoPrefix());
                }
            }

            /** @var PHPFunction[] $phpFunctions */
            $phpFunctions = $phpCode->getFunctions();
            foreach ($phpFunctions as $functionName => $function) {
                if (in_array($functionName, $this->getBuiltIns())) {
                    continue;
                }
                $functionSymbol = $this->discoveredSymbols->getFunction($functionName);
                if (is_null($functionSymbol)) {
                    $functionSymbol = new FunctionSymbol($functionName, $file, $namespaceSymbol);
                    $this->add($functionSymbol);
                }
                $functionSymbol->addSourceFile($file);
                $functionSymbol->setDoRename($file->isDoPrefix());
            }

            /** @var PHPConst[] $phpConstants */
            $phpConstants = $phpCode->getConstants();
            foreach ($phpConstants as $constantName => $constant) {
                $constantSymbol = $this->discoveredSymbols->getConst($constantName);
                if (is_null($constantSymbol)) {
                    $constantSymbol = new ConstantSymbol($constantName, $file, $namespaceSymbol);
                    $this->add($constantSymbol, $file);
                }
                $constantSymbol->addSourceFile($file);
                $constantSymbol->setDoRename($file->isDoPrefix());
            }

            $phpInterfaces = $phpCode->getInterfaces();
            foreach ($phpInterfaces as $interfaceName => $interface) {
                $interfaceSymbol = $this->discoveredSymbols->getInterface($interfaceName);
                if (is_null($interfaceSymbol)) {
                    $interfaceSymbol = new InterfaceSymbol($interfaceName, $file, $namespaceSymbol);
                    $this->add($interfaceSymbol);
                }
                $interfaceSymbol->addSourceFile($file);
                $interfaceSymbol->setDoRename($file->isDoPrefix());
            }

            $phpTraits = $phpCode->getTraits();
            foreach ($phpTraits as $traitName => $trait) {
                $traitSymbol = $this->discoveredSymbols->getTrait($traitName);
                if (is_null($traitSymbol)) {
                    $traitSymbol = new TraitSymbol($traitName, $file, $namespaceSymbol);
                    $this->add($traitSymbol);
                }
                $traitSymbol->addSourceFile($file);
                $traitSymbol->setDoRename($file->isDoPrefix());
            }

            // TODO: enum.
        }
    }

    protected function add(DiscoveredSymbol $symbol, ?FileBase $file = null): void
    {
        if (in_array($symbol->getOriginalSymbol(), $this->getBuiltIns())) {
            $this->logger->debug('Skipping built-in symbol {symbolName}, possible a polyfill.', [
                'symbolName' => $symbol->getOriginalLocalName(),
            ]);
            return;
        }

        $this->discoveredSymbols->add($symbol);

        if ($file instanceof FileWithDependency) {
            $file->getDependency()->addDiscoveredSymbol($symbol);
        }

        $level = in_array($symbol->getOriginalSymbol(), $this->loggedSymbols) ? 'debug' : 'info';
        $newText = in_array($symbol->getOriginalSymbol(), $this->loggedSymbols) ? '' : 'new ';

        $this->loggedSymbols[] = $symbol->getOriginalSymbol();

        $this->logger->log(
            $level,
            sprintf(
                "Found %s%s:::%s",
                $newText,
                // From `BrianHenryIE\Strauss\Types\TraitSymbol` -> `trait`
                strtolower(str_replace('Symbol', '', array_reverse(explode('\\', get_class($symbol)))[0])),
                $symbol->getOriginalSymbol()
            )
        );
    }

    /**
     * @param string $contents
     * @return array<string,string>
     */
    protected function splitByNamespace(string $contents):array
    {
        $result = [];

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $ast = $parser->parse(trim($contents)) ?? [];
        } catch (\PhpParser\Error $e) {
            $this->logger->error('Parse error: ' . $e->getMessage());
            return [];
        }

        foreach ($ast as $rootNode) {
            if ($rootNode instanceof Node\Stmt\Namespace_) {
                if (is_null($rootNode->name)) {
                    if (count($ast) === 1) {
                        $result['\\'] = $contents;
                    } else {
                        $result['\\'] = '<?php' . PHP_EOL . PHP_EOL . (new Standard())->prettyPrintFile($rootNode->stmts);
                    }
                } else {
                    $namespaceName = $rootNode->name->name;
                    if (count($ast) === 1) {
                        $result[$namespaceName] = $contents;
                    } else {
                        // This was failing for `phpoffice/phpspreadsheet/src/PhpSpreadsheet/Writer/Xlsx/FunctionPrefix.php`
                        $result[$namespaceName] = '<?php' . PHP_EOL . PHP_EOL . 'namespace ' . $namespaceName . ';' . PHP_EOL . PHP_EOL . (new Standard())->prettyPrintFile($rootNode->stmts);
                    }
                }
            }
        }

        // TODO: is this necessary?
        if (empty($result)) {
            $result['\\'] = '<?php' . PHP_EOL . PHP_EOL . $contents;
        }

        return $result;
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
        if (in_array($fqdnClassname, $this->getBuiltIns())) {
            $this->logger->debug('Skipping built-in symbol {symbolName}, possible a polyfill.', [
                'symbolName' => $fqdnClassname,
            ]);
            return null;
        }

        $classSymbol = $this->discoveredSymbols->getClass($fqdnClassname);
        if (is_null($classSymbol)) {
            $classSymbol = new ClassSymbol($fqdnClassname, $file, $isAbstract, $namespace, $extends, $interfaces);
            $this->add($classSymbol, $file);
        }
        $classSymbol->addSourceFile($file);
        if ($file instanceof FileWithDependency) {
            $file->addDiscoveredSymbol($classSymbol);
        }
        return $classSymbol;
    }

    protected function addDiscoveredNamespaceChange(string $fqdnNamespace, FileBase $file): NamespaceSymbol
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
    }
}
