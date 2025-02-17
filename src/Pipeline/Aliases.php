<?php
/**
 * When replacements are made in-situ in the vendor directory, add aliases for the original class fqdns so
 * dev dependencies can still be used.
 *
 * We could make the replacements in the dev dependencies but it is preferable not to edit files unnecessary.
 * Composer would warn of changes before updating (although it should probably do that already).
 * This approach allows symlinked dev dependencies to be used.
 * It also should work without knowing anything about the dev dependencies
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Config\AliasesConfigInterace;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Helpers\NamespaceSort;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Composer\ClassMapGenerator\ClassMapGenerator;
use League\Flysystem\StorageAttributes;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Aliases
{
    use LoggerAwareTrait;

    protected AliasesConfigInterace $config;
    protected string $workingDir;
    protected FileSystem $fileSystem;

    public function __construct(
        AliasesConfigInterace $config,
        string $workingDir,
        FileSystem $fileSystem,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;

        $this->workingDir = $config->isDryRun() ? 'mem://' . ltrim($workingDir, '/') : $workingDir;
        $this->fileSystem = $fileSystem;

        $this->setLogger($logger ?? new NullLogger());
    }

    protected function getVendorClassmap(): array
    {
        $vendorAbsoluteDirectory = $this->workingDir . $this->config->getVendorDirectory();
        $paths = array_map(
            function ($file) {
                return $this->config->isDryRun()
                    ? new \SplFileInfo('mem://'.$file->path())
                    : new \SplFileInfo('/'.$file->path());
            },
            array_filter(
                $this->fileSystem->listContents($vendorAbsoluteDirectory, true)->toArray(),
                fn(StorageAttributes $file) => $file->isFile() && in_array(substr($file->path(), -3), ['php', 'inc', '.hh'])
            )
        );

        $vendorClassmap = ClassMapGenerator::createMap($paths);

        return $vendorClassmap;
    }

    /**
     * @return array<string,string> FQDN => absolute path
     */
    protected function getTargetClassmap(): array
    {
        $paths =
            array_map(
                function ($file) {
                    return $this->config->isDryRun()
                        ? new \SplFileInfo('mem://'.$file->path())
                        : new \SplFileInfo('/'.$file->path());
                },
                array_filter(
                    $this->fileSystem->listContents($this->workingDir . $this->config->getTargetDirectory(), \League\Flysystem\FilesystemReader::LIST_DEEP)->toArray(),
                    fn(StorageAttributes $file) => $file->isFile() && in_array(substr($file->path(), -3), ['php', 'inc', '.hh'])
                )
            );

        $classMap = ClassMapGenerator::createMap($paths);

        // To make it easier when viewing in xdebug.
        uksort($classMap, new NamespaceSort());

         return $classMap;
    }

    /**
     * We will create `vendor/composer/autoload_aliases.php` alongside other autoload files, e.g. `autoload_real.php`.
     */
    protected function getAliasFilepath(): string
    {
        return  sprintf(
            '%s%scomposer/autoload_aliases.php',
            $this->workingDir,
            $this->config->getVendorDirectory()
        );
    }

    public function writeAliasesFileForSymbols(DiscoveredSymbols $symbols): void
    {

        $outputFilepath = $this->getAliasFilepath();

        $fileString = $this->buildStringOfAliases($symbols, basename($outputFilepath));

        if (empty($fileString)) {
            // Log?
            return;
        }

        $this->fileSystem->write($outputFilepath, $fileString);

        $autoloadRealFilepath = $this->workingDir . $this->config->getVendorDirectory() . 'composer/autoload_real.php';

        $composerFileString = $this->fileSystem->read($autoloadRealFilepath);

        $newComposerAutoloadReal = $this->addAliasesFileToComposer($composerFileString);

        $this->fileSystem->write($autoloadRealFilepath, $newComposerAutoloadReal);
    }

    /**
     * @param DiscoveredSymbols $symbols
     * @return array<NamespaceSymbol|ConstantSymbol|ClassSymbol|FunctionSymbol>
     */
    protected function getModifiedSymbols(DiscoveredSymbols $symbols): array
    {
        $modifiedSymbols = [];
        foreach ($symbols->getSymbols() as $symbol) {
            if ($symbol->getOriginalSymbol() !== $symbol->getReplacement()) {
                $modifiedSymbols[] = $symbol;
            }
        }
        return $modifiedSymbols;
    }

    protected function buildStringOfAliases(DiscoveredSymbols $symbols, string $outputFilename): string
    {

        $sourceDirClassmap = $this->getVendorClassmap();

        $autoloadAliasesFileString = '<?php' . PHP_EOL . PHP_EOL . '// ' . $outputFilename . ' @generated by Strauss' . PHP_EOL . PHP_EOL;

        $modifiedSymbols = $this->getModifiedSymbols($symbols);

        $targetDirClasssmap = $this->getTargetClassmap();


        foreach ($modifiedSymbols as $symbol) {
            $originalSymbol = $symbol->getOriginalSymbol();
            $replacementSymbol = $symbol->getReplacement();

//            if (!$symbol->getSourceFile()->isDoDelete()) {
//                $this->logger->debug("Skipping {$originalSymbol} because it is not marked for deletion.");
//                continue;
//            }

            if ($originalSymbol === $replacementSymbol) {
                $this->logger->debug("Skipping {$originalSymbol} because it is not being changed.");
                continue;
            }

            switch (get_class($symbol)) {
                case NamespaceSymbol::class:
                    // TODO: namespaced constants?

                    $symbolSourceFiles = $symbol->getSourceFiles();

                    $namespaceInOriginalClassmap = array_filter(
                        $sourceDirClassmap,
                        fn($filepath) => in_array($filepath, array_keys($symbolSourceFiles))
                    );

                    $php = "namespace {$symbol->getOriginalSymbol()} {" . PHP_EOL;

                    foreach ($namespaceInOriginalClassmap as $originalFqdnClassName => $absoluteFilePath) {
                        if ($symbol->getOriginalSymbol() === $symbol->getReplacement()) {
                            continue;
                        }

                        $localName = array_reverse(explode('\\', $originalFqdnClassName))[0];

                        if (0 !== strpos($originalFqdnClassName, $symbol->getReplacement())) {
                            $newFqdnClassName = $symbol->getReplacement() . '\\' . $localName;
                        } else {
                            $newFqdnClassName = $originalFqdnClassName;
                        }

                        if (!isset($targetDirClasssmap[$newFqdnClassName]) && !isset($sourceDirClassmap[$originalFqdnClassName])) {
                            $a = $symbol->getSourceFiles();
                            /** @var File $b */
                            $b = array_pop($a); // There's gotta be at least one.

                            throw new \Exception("errorrrr " . ' ' . basename($b->getAbsoluteTargetPath()) . ' ' . $originalFqdnClassName . ' ' . $newFqdnClassName . PHP_EOL. PHP_EOL);
                        }

                        $symbolFilepath = $targetDirClasssmap[$newFqdnClassName] ?? $sourceDirClassmap[$originalFqdnClassName];
                        $symbolFileString = $this->fileSystem->read($symbolFilepath);

                        // This should be improved with a check for non-class-valid characters after the name.
                        // Eventually it should be in the File object itself.
                        $isClass = 1 === preg_match('/class '.$localName.'/i', $symbolFileString);
                        $isInterface = 1 === preg_match('/interface '.$localName.'/i', $symbolFileString);
                        $isTrait = 1 === preg_match('/trait '.$localName.'/i', $symbolFileString);

                        if (!$isClass && !$isInterface && !$isTrait) {
                            $isEnum = 1 === preg_match('/enum '.$localName.'/', $symbolFileString);

                            if ($isEnum) {
                                $this->logger->warning("Skipping $newFqdnClassName – enum aliasing not yet implemented.");
                                // TODO: enums
                                continue;
                            }

                            $this->logger->error("Skipping $newFqdnClassName because it doesn't exist.");
                            throw new \Exception("Skipping $newFqdnClassName because it doesn't exist.");
                        }

                        if ($isClass) {
                            $php .= "  class_alias(\\$newFqdnClassName::class, \\$originalFqdnClassName::class);" . PHP_EOL;
                        } elseif ($isInterface) {
                            $php .= "  interface $localName extends \\$newFqdnClassName {};" . PHP_EOL;
                        } elseif ($isTrait) {
                            $php .= "  trait $localName { use \\$newFqdnClassName; }" . PHP_EOL;
                        }
                    }

                    // End `namespace ... {`.
                    $php .= "}" . PHP_EOL;

                    break;
                case ConstantSymbol::class:
                    /**
                     * https://stackoverflow.com/questions/19740621/namespace-constants-and-use-as
                     */
                    // Ideally this would somehow be loaded after everything else.
                    // Maybe some Patchwork style redefining of `define()` to add the alias?
                    // Does it matter since all references to use the constant should have been updated to the new name anyway.
                    // TODO: global `const`.
                    $php = <<<EOD
if(defined('$originalSymbol')) { define('$replacementSymbol', $originalSymbol); }
EOD;
                    break;
                case ClassSymbol::class:
                    $alias = $symbol->getOriginalSymbol(); // We want the original to continue to work, so it is the alias.
                    $concreteClass = $symbol->getReplacement();
                    $php = <<<EOD
class_alias($concreteClass, $alias);
EOD;
                    break;
                case FunctionSymbol::class:
                    // TODO: Do we need to check for `void`? Or will it just be ignored?
                    // TODO: check `function_exists()`
                    // Is it possible to inherit PHPDoc from the original function?
                    $php = <<<EOD
function $originalSymbol(...\$args) { return \\$replacementSymbol(...\$args); }
EOD;
                    break;
                default:
                    throw new \InvalidArgumentException('Unknown symbol type: ' . get_class($symbol));
            }

            $php && $autoloadAliasesFileString .= $php . PHP_EOL;
        }

        return $autoloadAliasesFileString;
    }

    /**
     * Given the PHP code string for `vendor/composer/autoload_real.php`, add a `require_once autoload_aliases.php`
     * before the `return` statement of the `getLoader()` method.
     *
     * Ideally we want to load after the files autoloaders
     *
     * @param string $code
     */
    public function addAliasesFileToComposer(string $code): string
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        try {
            $ast = $parser->parse($code);
        } catch (Error $error) {
            $this->logger->error("Parse error: {$error->getMessage()}");
            return $code;
        }

        $getLoaderMethod = null;

        foreach ($ast as $fileLevelNode) {
//          if ($fileLevelNode instanceof Node::class) {
            if (get_class($fileLevelNode) === 'PhpParser\Node\Stmt\Class_') {
                foreach ($fileLevelNode->stmts as $classLevelStatementsNode) {
                    if (get_class($classLevelStatementsNode) === ClassMethod::class) {
                        if ($classLevelStatementsNode->name->name === 'getLoader') {
                            $getLoaderMethod = $classLevelStatementsNode;
                        }
                    }
                }
            }
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class extends NodeVisitorAbstract {
            public function leaveNode(Node $node)
            {
                if (get_class($node) === \PhpParser\Node\Stmt\Return_::class) {
                    $requireOnce = new Node\Stmt\Expression(
                        new Node\Expr\Include_(
                            new Node\Scalar\String_('autoload_aliases.php'),
                            Node\Expr\Include_::TYPE_REQUIRE_ONCE
                        )
                    );
//                  $requireOnce->setAttribute('comments', [new \PhpParser\Comment('// Include aliases file. This line added by Strauss')]);
                    // Add a blank line. Probably not the correct way to do this.
                    $requireOnce->setAttribute('comments', [new \PhpParser\Comment('')]);
//                  $requireOnce->setDocComment(new \PhpParser\Comment\Doc('/** @see  */'));
                    // Add a blank line. Probably not the correct way to do this.
                    $node->setAttribute('comments', [new \PhpParser\Comment('')]);

                    return [
                        $requireOnce,
                        $node
                    ];
                }
                return $node;
            }
        });

        $stmts = $getLoaderMethod->stmts;
        $modifiedStmts = $traverser->traverse($stmts);
        $getLoaderMethod->stmts = $modifiedStmts;

        $prettyPrinter = new Standard();
        $phpString = $prettyPrinter->prettyPrintFile($ast);

        return $phpString;
    }
}
