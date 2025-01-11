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

use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

class Aliases
{
    public function do(DiscoveredSymbols $symbols, string $workingDir): void
    {

        // For development, we'll just put it in the project root and manually load the file.
        // TODO Need to edit autoload_real.php to load the file.
        $outputFilename = $workingDir . '/autoload_renamed.php';

        $fileString = '<?php' . PHP_EOL . PHP_EOL . "// {$outputFilename} @generated by Strauss" . PHP_EOL;

        // Must be the authoritative classmap to work.
        $classmap = include $workingDir . '/vendor/composer/autoload_classmap.php';
        $prefixedClassmap = include $workingDir . '/vendor-prefixed/autoload-classmap.php';
        if (is_array($prefixedClassmap)) {
            spl_autoload_register(
                function ($classname) use ($prefixedClassmap) {
                    if (isset($prefixedClassmap[ $classname ]) && file_exists($prefixedClassmap[ $classname ])) {
                        require_once $prefixedClassmap[ $classname ];
                    }
                }
            );
        }

        foreach ($symbols->getSymbols() as $symbol) {
            $originalSymbol = $symbol->getOriginalSymbol();
            $replacementSymbol = $symbol->getReplacement();

            $php = null;

            switch (get_class($symbol)) {
                case NamespaceSymbol::class:
                    // TODO: namespaced constants?

                    // The original namespace before it was modified by Strauss.
                    $namespace = $symbol->getOriginalSymbol();

                    $php = "namespace {$symbol->getOriginalSymbol()} {" . PHP_EOL;

                    foreach ($classmap as $originalFqdnClassName => $absoluteFilePath) {
                        $originalNamespace = (function () use ($originalFqdnClassName) {
                            $parts = explode('\\', $originalFqdnClassName);
                            array_pop($parts);
                            return implode('\\', $parts);
                        })();
                        $localName = array_reverse(explode('\\', $originalFqdnClassName))[0];
                        $newFqdnClassName = str_replace($namespace, $symbol->getReplacement(), $originalFqdnClassName);
                        if ($originalNamespace === $namespace) {
                            $isClass = class_exists($newFqdnClassName);
                            $isInterface = interface_exists($newFqdnClassName);
                            $isTrait = trait_exists($newFqdnClassName);

                            if ($isClass) {
                                $php .= "  class_alias(\\$newFqdnClassName::class, \\$originalFqdnClassName::class);" . PHP_EOL;
                            } elseif ($isInterface) {
                                $php .= "  interface $localName extends \\$newFqdnClassName {};" . PHP_EOL;
                            } elseif ($isTrait) {
                                $php .= "  trait $localName { use \\$newFqdnClassName; }" . PHP_EOL;
                            }
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
                    $php = <<<EOD
function $originalSymbol(...\$args) { return \\$replacementSymbol(...\$args); }
EOD;
                    break;
                default:
                    throw new \InvalidArgumentException('Unknown symbol type: ' . get_class($symbol));
            }

            $php && $fileString .= $php . PHP_EOL;
        }

        if (empty($fileString)) {
            // Log?
            return;
        }

        file_put_contents($outputFilename, $fileString);
    }

    /**
     * Given the PHP code string for `vendor/composer/autoload_real.php`, add a `require_once autoload_aliases.php`
     * before the `return` statement of the `getLoader()` method.
     *
     * @param string $code
     */
    public function addAliasesFileToComposer(string $code): string
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        try {
            $ast = $parser->parse($code);
        } catch (Error $error) {
            echo "Parse error: {$error->getMessage()}\n";
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
