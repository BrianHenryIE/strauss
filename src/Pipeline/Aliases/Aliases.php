<?php
/**
 * When replacements are made in-situ in the vendor directory, add aliases for the original class fqdns so
 * dev dependencies can still be used.
 *
 * We could make the replacements in the dev dependencies but it is preferable not to edit files unnecessarily.
 * Composer would warn of changes before updating (although it should probably do that already).
 * This approach allows symlinked dev dependencies to be used.
 * It also should work without knowing anything about the dev dependencies
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Pipeline\Aliases;

use BrianHenryIE\Strauss\Config\AliasesConfigInterface;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Types\AutoloadAliasInterface;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Aliases
{
    use LoggerAwareTrait;

    protected AliasesConfigInterface $config;

    protected FileSystem $fileSystem;

    public function __construct(
        AliasesConfigInterface $config,
        FileSystem $fileSystem,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->fileSystem = $fileSystem;
        $this->setLogger($logger ?? new NullLogger());
    }

    protected function getTemplate(array $aliasesArray, ?string $autoloadAliasesFunctionsString): string
    {
        $namespace = $this->config->getNamespacePrefix();
        $autoloadAliases = var_export($aliasesArray, true);

        $globalFunctionsString = !$autoloadAliasesFunctionsString ? ''
                : <<<GLOBAL
                // Functions and constants
                $autoloadAliasesFunctionsString
                GLOBAL;

        $template = file_get_contents(__DIR__ . '/autoload_aliases.template.php');

        $template = str_replace(
            '// FunctionsAndConstants',
            $globalFunctionsString,
            $template
        );

        $template = str_replace(
            'namespace BrianHenryIE\Strauss {',
            'namespace ' . trim($namespace, '\\') . ' {',
            $template
        );

        $template = str_replace(
            'private array $autoloadAliases = [];',
            "private array \$autoloadAliases = $autoloadAliases;",
            $template
        );

        return $template;
    }

    public function writeAliasesFileForSymbols(DiscoveredSymbols $symbols): void
    {
//        $modifiedSymbols = $this->getModifiedSymbols($symbols);

        $outputFilepath = $this->getAliasFilepath();

        $fileString = $this->buildStringOfAliases($symbols, basename($outputFilepath));

        $this->fileSystem->write($outputFilepath, $fileString);
    }

    /**
     * We will create `vendor/composer/autoload_aliases.php` alongside other autoload files, e.g. `autoload_real.php`.
     */
    protected function getAliasFilepath(): string
    {
        return  sprintf(
            '%scomposer/autoload_aliases.php',
            $this->config->getVendorDirectory()
        );
    }

    protected function getModifiedSymbols(DiscoveredSymbols $symbols): DiscoveredSymbols
    {
        $modifiedSymbols = new DiscoveredSymbols();
        foreach ($symbols->getAll() as $symbol) {
            if ($symbol->getOriginalSymbol() !== $symbol->getReplacement()) {
                $modifiedSymbols->add($symbol);
            }
            if ($symbol instanceof FunctionSymbol) {
                $functionNamespace = $symbols->getNamespaceSymbolByString($symbol->getNamespace());
                $isFunctionHasChangedNamespace = $functionNamespace->isChangedNamespace();

                if ($isFunctionHasChangedNamespace || $symbol->getOriginalSymbol() !== $symbol->getReplacement()
                ) {
                    $modifiedSymbols->add($symbol);
                }
            }
        }
        return $modifiedSymbols;
    }

    protected function registerAutoloader(array $classmap): void
    {

        // Need to autoload the classes for reflection to work (this is maybe just an issue during tests).
        spl_autoload_register(function (string $class) use ($classmap) {
            if (isset($classmap[$class])) {
                $this->logger->debug("Autoloading $class from {$classmap[$class]}");
                try {
                    include_once $classmap[$class];
                } catch (\Throwable $e) {
                    if (false !== strpos($e->getMessage(), 'PHPUnit')) {
                        $this->logger->warning("Error autoloading $class from {$classmap[$class]}: " . $e->getMessage());
                    } else {
                        $this->logger->error("Error autoloading $class from {$classmap[$class]}: " . $e->getMessage());
                    }
                }
            }
        });
    }

    protected function buildStringOfAliases(DiscoveredSymbols $modifiedSymbols, string $outputFilename): string
    {
        // TODO: When target !== vendor, there should be a test here to ensure the target autoloader is included, with instructions to add it.

        $autoloadAliasesFunctionsString = $this->getFunctionAliasesString($modifiedSymbols);

        $aliasesArray = $this->getAliasesArray($modifiedSymbols);

        $autoloadAliasesFileString = $this->getTemplate($aliasesArray, $autoloadAliasesFunctionsString);

        return $autoloadAliasesFileString;
    }

    /**
     * @param ClassSymbol $modifiedSymbols
     * @param array $sourceDirClassmap
     * @param array $targetDirClasssmap
     *
     * @return array{}
     * @throws \League\Flysystem\FilesystemException
     */
    protected function getAliasesArray(DiscoveredSymbols $symbols): array
    {
        $result = [];

        foreach ($symbols->getAll() as $originalSymbolFqdn => $symbol) {
            if ($symbol->getOriginalSymbol() === $symbol->getReplacement()) {
                continue;
            }
            if (!($symbol instanceof AutoloadAliasInterface)) {
                continue;
            }
            $result[$originalSymbolFqdn] = $symbol->getAutoloadAliasArray();
        }

        return $result;
    }

    protected function getFunctionAliasesString(DiscoveredSymbols $discoveredSymbols): string
    {
        $modifiedSymbols = $discoveredSymbols->getSymbols();

        $autoloadAliasesFileString = '';

        $symbolsByNamespace = ['\\' => []];
        foreach ($modifiedSymbols as $symbol) {
            if ($symbol instanceof FunctionSymbol) {
                if (!isset($symbolsByNamespace[$symbol->getNamespace()])) {
                    $symbolsByNamespace[$symbol->getNamespace()] = [];
                }
                $symbolsByNamespace[$symbol->getNamespace()][] = $symbol;
            }
            /**
             * "define() will define constants exactly as specified.  So, if you want to define a constant in a
             * namespace, you will need to specify the namespace in your call to define(), even if you're calling
             * define() from within a namespace."
             * @see https://www.php.net/manual/en/function.define.php
             */
            if ($symbol instanceof ConstantSymbol) {
                $symbolsByNamespace['\\'][] = $symbol;
            }
        }

        if (!empty($symbolsByNamespace['\\'])) {
            $globalAliasesPhpString = 'namespace {' . PHP_EOL;

            /** @var FunctionSymbol & ConstantSymbol $symbol */
            foreach ($symbolsByNamespace['\\'] as $symbol) {
                $aliasesPhpString = '';

                $originalLocalSymbol = $symbol->getOriginalSymbol();
                $replacementSymbol   = $symbol->getReplacement();

                if ($originalLocalSymbol === $replacementSymbol) {
                    continue;
                }

                switch (get_class($symbol)) {
                    case FunctionSymbol::class:
                        // TODO: Do we need to check for `void`? Or will it just be ignored?
                        // Is it possible to inherit PHPDoc from the original function?
                        $aliasesPhpString = $this->aliasedFunctionTemplate($originalLocalSymbol, $replacementSymbol);
                        break;
                    case ConstantSymbol::class:
                        /**
                         * https://stackoverflow.com/questions/19740621/namespace-constants-and-use-as
                         */
                        // Ideally this would somehow be loaded after everything else.
                        // Maybe some Patchwork style redefining of `define()` to add the alias?
                        // Does it matter since all references to use the constant should have been updated to the new name anyway.
                        // TODO: global `const`.
                        $aliasesPhpString = <<<EOD
        if(!defined('$originalLocalSymbol') && defined('$replacementSymbol')) { 
            define('$originalLocalSymbol', $replacementSymbol); 
        }
        EOD;
                        break;
                    default:
                        /**
                         * Should be addressed above.
                         *
                         * @see self::appendAliasString())
                         */
                        break;
                }

                $globalAliasesPhpString .= $aliasesPhpString;
            }

            $globalAliasesPhpString .= PHP_EOL . '}' . PHP_EOL; // Close global namespace.

            $autoloadAliasesFileString = $autoloadAliasesFileString . PHP_EOL . $globalAliasesPhpString;
        }

        unset($symbolsByNamespace['\\']);
        foreach ($symbolsByNamespace as $namespaceSymbol => $symbols) {
            $aliasesPhpString = "namespace $namespaceSymbol {" . PHP_EOL;

            foreach ($symbols as $symbol) {
                $originalLocalSymbol = $symbol->getOriginalLocalName();

                $namespaceSymbol = $discoveredSymbols->getNamespaceSymbolByString($symbol->getNamespace());

                if (!($symbol instanceof FunctionSymbol
                   &&
                   $namespaceSymbol->isChangedNamespace())
                ) {
                    $this->logger->debug("Skipping {$originalLocalSymbol} because it is not being changed.");
                    continue;
                }

                $unNamespacedOriginalSymbol = trim(str_replace($symbol->getNamespace(), '', $originalLocalSymbol), '\\');
                $namespacedOriginalSymbol = $symbol->getNamespace() . '\\' . $unNamespacedOriginalSymbol;

                $replacementSymbol = str_replace(
                    $namespaceSymbol->getOriginalSymbol(),
                    $namespaceSymbol->getReplacement(),
                    $namespacedOriginalSymbol
                );

                $aliasesPhpString .= $this->aliasedFunctionTemplate(
                    $namespacedOriginalSymbol,
                    $replacementSymbol,
                );
            }
            $aliasesPhpString .= "}" . PHP_EOL; // Close namespace.

            $autoloadAliasesFileString .= $aliasesPhpString;
        }

        return $autoloadAliasesFileString;
    }

    /**
     * Returns the PHP for `if(!function_exists...` for an aliased function.
     *
     * Ensures the correct leading backslashes.
     *
     * @param string $namespacedOriginalFunction
     * @param string $namespacedReplacementFunction
     */
    protected function aliasedFunctionTemplate(
        string $namespacedOriginalFunction,
        string $namespacedReplacementFunction
    ): string {
        $namespacedOriginalFunction = '\\\\' . trim($namespacedOriginalFunction, '\\');
        $namespacedOriginalFunction = preg_replace('/\\\\+/', '\\\\\\\\', $namespacedOriginalFunction);

        $localOriginalFunction = array_reverse(explode('\\', $namespacedOriginalFunction))[0];

        $namespacedReplacementFunction = '\\' . trim($namespacedReplacementFunction, '\\');
        $namespacedReplacementFunction = preg_replace('/\\\\+/', '\\', $namespacedReplacementFunction);

        return <<<EOD
                    if(!function_exists('$namespacedOriginalFunction')){
                        function $localOriginalFunction(...\$args) {
                            return $namespacedReplacementFunction(...func_get_args());
                        }
                    }
                EOD . PHP_EOL;
    }
}
