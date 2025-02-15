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
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use League\Flysystem\FilesystemReader;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class FileSymbolScanner
{
    use LoggerAwareTrait;

    /** @var string[]  */
    protected array $excludeNamespacesFromPrefixing = array();

    protected DiscoveredSymbols $discoveredSymbols;

    protected FilesystemReader $filesystem;

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
            if (!$file->isPhpFile()) {
                $this->logger->debug('Skipping non-PHP file: ' . $file->getSourcePath(getcwd()));
                continue;
            }

            $this->logger->info('Scanning file:        ' . $file->getSourcePath(getcwd()));
            $this->find(
                $this->filesystem->read($file->getSourcePath()),
                $file
            );
        }

        return $this->discoveredSymbols;
    }

    /**
     * TODO: Don't use preg_replace_callback!
     *
     * @uses self::addDiscoveredNamespaceChange()
     * @uses self::addDiscoveredClassChange()
     */
    protected function find(string $contents, File $file): void
    {
        // If the entire file is under one namespace, all we want is the namespace.
        // If there were more than one namespace, it would appear as `namespace MyNamespace { ...`,
        // a file with only a single namespace will appear as `namespace MyNamespace;`.
        $singleNamespacePattern = '/
            (<?php|\r\n|\n)                                              # A new line or the beginning of the file.
            \s*                                                          # Allow whitespace before
            namespace\s+(?<namespace>[0-9A-Za-z_\x7f-\xff\\\\]+)[\s\n]*; # Match a single namespace in the file.
        /x'; //  # x: ignore whitespace in regex.
        if (1 === preg_match($singleNamespacePattern, $contents, $matches)) {
            $this->addDiscoveredNamespaceChange($matches['namespace'], $file);

            return;
        }

        if (0 < preg_match_all('/\s*define\s*\(\s*["\']([^"\']*)["\']\s*,\s*["\'][^"\']*["\']\s*\)\s*;/', $contents, $constants)) {
            foreach ($constants[1] as $constant) {
                $constantObj = new ConstantSymbol($constant, $file);
                $this->add($constantObj);
            }
        }

        // TODO traits

        // TODO: Is the ";" in this still correct since it's being taken care of in the regex just above?
        // Looks like with the preceding regex, it will never match.

        preg_replace_callback(
            '
			~											# Start the pattern
				[\r\n]+\s*namespace\s+([a-zA-Z0-9_\x7f-\xff\\\\]+)[;{\s\n]{1}[\s\S]*?(?=namespace|$) 
														# Look for a preceding namespace declaration, 
														# followed by a semicolon, open curly bracket, space or new line
														# up until a 
														# potential second namespace declaration or end of file.
														# if found, match that much before continuing the search on
				|										# the remainder of the string.
				\/\*[\s\S]*?\*\/ |                      # Skip multiline comments
				^\s*\/\/.*$	|   						# Skip single line comments
				\s*										# Whitespace is allowed before 
				(?:abstract\sclass|class|interface)\s+	# Look behind for class, abstract class, interface
				([a-zA-Z0-9_\x7f-\xff]+)				# Match the word until the first non-classname-valid character
				\s?										# Allow a space after
				(?:{|extends|implements|\n|$)			# Class declaration can be followed by {, extends, implements 
														# or a new line
			~x', //                                     # x: ignore whitespace in regex.
            function ($matches) use ($file) {

                // If we're inside a namespace other than the global namespace:
                if (1 === preg_match('/^\s*namespace\s+[a-zA-Z0-9_\x7f-\xff\\\\]+[;{\s\n]{1}.*/', $matches[0])) {
                    $this->addDiscoveredNamespaceChange($matches[1], $file);

                    return $matches[0];
                }

                if (count($matches) < 3) {
                    return $matches[0];
                }

                // TODO: Why is this [2] and not [1] (which seems to be always empty).
                $this->addDiscoveredClassChange($matches[2], $file);

                return $matches[0];
            },
            $contents
        );

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($contents);

        $traverser = new NodeTraverser();
        $visitor = new class extends \PhpParser\NodeVisitorAbstract {
            protected array $functions = [];
            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Function_) {
                    $this->functions[] = $node->name->name;
                }
                return $node;
            }

            /**
             * @return string[] Function names.
             */
            public function getFunctions(): array
            {
                return $this->functions;
            }
        };
        $traverser->addVisitor($visitor);

        /** @var Node $node */
        foreach ((array) $ast as $node) {
            $traverser->traverse([ $node ]);
        }
        foreach ($visitor->getFunctions() as $functionName) {
            if (in_array($functionName, $this->getBuiltIns())) {
                continue;
            }
            $functionSymbol = new FunctionSymbol($functionName, $file);
            $this->add($functionSymbol);
        }
    }

    protected function addDiscoveredClassChange(string $classname, File $file): void
    {
        // TODO: This should be included but marked not to prefix.
        if (in_array($classname, $this->getBuiltIns())) {
            return;
        }

        $classSymbol = new ClassSymbol($classname, $file);
        $this->add($classSymbol);
    }

    protected function addDiscoveredNamespaceChange(string $namespace, File $file): void
    {

        foreach ($this->excludeNamespacesFromPrefixing as $excludeNamespace) {
            if (0 === strpos($namespace, $excludeNamespace)) {
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
