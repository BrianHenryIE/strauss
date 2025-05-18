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

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Config\AliasesConfigInterface;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Helpers\NamespaceSort;
use BrianHenryIE\Strauss\Types\AutoloadAliasInterface;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Composer\ClassMapGenerator\ClassMapGenerator;
use League\Flysystem\StorageAttributes;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;

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
				// Global functions
				namespace {
					$autoloadAliasesFunctionsString
				}
				GLOBAL;

        return <<<TEMPLATE
				<?php
				
				$globalFunctionsString
				
				// Everything else – irrelevant that this part is namespaced
				namespace $namespace {
					
				class AliasAutoloader
				{
					private string \$includeFilePath;
				
				    private array \$autoloadAliases = $autoloadAliases;
				
				    public function __construct() {
						\$this->includeFilePath = __DIR__ . '/autoload_alias.php';
				    }
				    
				    public function autoload(\$class)
				    {
				        if (!isset(\$this->autoloadAliases[\$class])) {
				            return;
				        }
				        switch (\$this->autoloadAliases[\$class]['type']) {
				            case 'class':
				                \$this->load(
				                    \$this->classTemplate(
				                        \$this->autoloadAliases[\$class]
				                    )
				                );
				                break;
				            case 'interface':
				                \$this->load(
				                    \$this->interfaceTemplate(
				                        \$this->autoloadAliases[\$class]
				                    )
				                );
				                break;
				            case 'trait':
				                \$this->load(
				                    \$this->traitTemplate(
				                        \$this->autoloadAliases[\$class]
				                    )
				                );
				                break;
				            default:
				                // Never.
				                break;
				        }
				    }
				
				    private function load(string \$includeFile)
				    {
				        file_put_contents(\$this->includeFilePath, \$includeFile);
				        include \$this->includeFilePath;
				        file_exists(\$this->includeFilePath) && unlink(\$this->includeFilePath);
				    }
					
					// TODO: What if this was a real function in this class that could be used for testing, which would be read and written by php-parser?
				    private function classTemplate(array \$class): string
				    {
				        \$classname = \$class['classname'];
				        if(isset(\$class['namespace'])) {
				            \$namespace = "namespace {\$class['namespace']};";
				            \$extends = '\\\\' . \$class['extends'];
					        \$implements = !empty(\$class['implements']) ? ''
					            : ' implements \\\\' . implode(', \\\\', \$class['implements']);
				        } else {
				            \$namespace = '';
				            \$extends = \$class['extends'];
					        \$implements = !empty(\$class['implements']) ? ''
					            : ' implements ' . implode(', ', \$class['implements']);
				        }
				        return <<<EOD
								<?php
								\$namespace
								class \$classname extends \$extends \$implements {}
								EOD;
				    }
				    
				    private function interfaceTemplate(array \$interface): string
				    {
				        \$interfacename = \$interface['classname'];
				        \$namespace = isset(\$interface['namespace']) 
				            ? "namespace {\$interface['namespace']};" : '';
				        \$extends = isset(\$interface['namespace'])
				            ? '\\\\' . implode('\\\\ ,', \$interface['extends'])
				            : implode(', ', \$interface['extends']);
				        return <<<EOD
								<?php
								\$namespace
								interface \$interfacename extends \$extends {}
								EOD;
				    } 
				    private function traitTemplate(array \$trait): string
				    {
				        \$traitname = \$trait['traitname'];
				        \$namespace = isset(\$trait['namespace']) 
				            ? "namespace {\$trait['namespace']};" : '';
				        \$uses = isset(\$trait['namespace'])
				            ? '\\\\' . implode(';' . PHP_EOL . '    use \\\\', \$trait['extends'])
				            : implode(';' . PHP_EOL . '    use ', \$trait['extends']);
				        return <<<EOD
								<?php
								\$namespace
								trait \$traitname { 
								    use \$uses; 
								}
								EOD;
					    }
					}
					
					spl_autoload_register( [ new AliasAutoloader(), 'autoload' ] );

				}
				TEMPLATE;
    }

    public function writeAliasesFileForSymbols(DiscoveredSymbols $symbols): void
    {
        $outputFilepath = $this->getAliasFilepath();

        $fileString = $this->buildStringOfAliases($symbols, basename($outputFilepath));

        if (empty($fileString)) {
            // TODO: Check if no actual aliases were added (i.e. is it just an empty template).
            // Log?
            return;
        }

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

    /**
     * @param DiscoveredSymbol[] $symbols
     * @return DiscoveredSymbol[]
     */
    protected function getModifiedSymbols(array $symbols): array
    {
        $modifiedSymbols = [];
        foreach ($symbols as $symbol) {
            if ($symbol->getOriginalSymbol() !== $symbol->getReplacement()) {
                $modifiedSymbols[] = $symbol;
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

    protected function buildStringOfAliases(DiscoveredSymbols $symbols, string $outputFilename): string
    {

        $autoloadAliasesFileString = '<?php' . PHP_EOL . PHP_EOL . '// ' . $outputFilename . ' @generated by Strauss' . PHP_EOL . PHP_EOL;

        $autoloadAliasesFileString .= "namespace " . trim($this->config->getNamespacePrefix(), '\\') . ";" . PHP_EOL . PHP_EOL;

        // TODO: When target !== vendor, there should be a test here to ensure the target autoloader is included, with instructions to add it.

        $modifiedSymbols = $this->getModifiedSymbols($symbols->getSymbols());

        $functionSymbols = array_filter($modifiedSymbols, fn(DiscoveredSymbol $symbol) => $symbol instanceof FunctionSymbol);
        $otherSymbols = array_filter($modifiedSymbols, fn(DiscoveredSymbol $symbol) => !($symbol instanceof FunctionSymbol));

        $autoloadAliasesFunctionsString = count($functionSymbols)>0
            ? $this->appendFunctionAliases($functionSymbols, $autoloadAliasesFileString)
            : null;
        $aliasesArray = $this->getAliasesArray($otherSymbols, $symbols);

        $autoloadAliasesFileString = $this->getTemplate($aliasesArray, $autoloadAliasesFunctionsString);

        return $autoloadAliasesFileString;
    }

    /**
     * @param array<NamespaceSymbol|ClassSymbol> $modifiedSymbols
     * @param array $sourceDirClassmap
     * @param array $targetDirClasssmap
     * @param string $autoloadAliasesFileString
     * @return array{}
     * @throws \League\Flysystem\FilesystemException
     */
    protected function getAliasesArray(array $modifiedSymbols, DiscoveredSymbols $symbols): array
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

    protected function appendFunctionAliases(array $modifiedSymbols, string $autoloadAliasesFileString): string
    {
        $aliasesPhpString = '';

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
                case FunctionSymbol::class:
                    // TODO: Do we need to check for `void`? Or will it just be ignored?
                    // Is it possible to inherit PHPDoc from the original function?
                    $aliasesPhpString = <<<EOD
        if(!function_exists('$originalSymbol')){
            function $originalSymbol(...\$args) { return $replacementSymbol(func_get_args()); }
        }
        EOD;
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
        if(!defined('$originalSymbol') && defined('$replacementSymbol')) { 
            define('$originalSymbol', $replacementSymbol); 
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

            $autoloadAliasesFileString .= $aliasesPhpString;
        }

        return $autoloadAliasesFileString;
    }
}
