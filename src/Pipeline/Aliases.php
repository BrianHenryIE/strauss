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
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Aliases
{
    use LoggerAwareTrait;

    protected AliasesConfigInterace $config;

    protected FileSystem $fileSystem;

    public function __construct(
        AliasesConfigInterace $config,
        FileSystem $fileSystem,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->fileSystem = $fileSystem;
        $this->setLogger($logger ?? new NullLogger());
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

    protected function getVendorClassmap(): array
    {
        $paths = array_map(
            function ($file) {
                return $this->config->isDryRun()
                    ? new \SplFileInfo('mem://'.$file->path())
                    : new \SplFileInfo('/'.$file->path());
            },
            array_filter(
                $this->fileSystem->listContents($this->config->getVendorDirectory(), true)->toArray(),
                fn(StorageAttributes $file) => $file->isFile() && in_array(substr($file->path(), -3), ['php', 'inc', '.hh'])
            )
        );

        $vendorClassmap = ClassMapGenerator::createMap($paths);

        $vendorClassmap = array_map(fn($path) => str_replace('mem://', '', $path), $vendorClassmap);

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
                    $this->fileSystem->listContents($this->config->getTargetDirectory(), \League\Flysystem\FilesystemReader::LIST_DEEP)->toArray(),
                    fn(StorageAttributes $file) => $file->isFile() && in_array(substr($file->path(), -3), ['php', 'inc', '.hh'])
                )
            );

        $classMap = ClassMapGenerator::createMap($paths);

        // To make it easier when viewing in xdebug.
        uksort($classMap, new NamespaceSort());

        $classMap = array_map(fn($path) => str_replace('mem://', '', $path), $classMap);

        return $classMap;
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
     * @return array<NamespaceSymbol|ConstantSymbol|ClassSymbol|FunctionSymbol>
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

    protected function buildStringOfAliases(DiscoveredSymbols $symbols, string $outputFilename): string
    {

        $sourceDirClassmap = $this->getVendorClassmap();

        $autoloadAliasesFileString = '<?php' . PHP_EOL . PHP_EOL . '// ' . $outputFilename . ' @generated by Strauss' . PHP_EOL . PHP_EOL;

        // TODO: When target !== vendor, there should be a test here to ensure the target autoloader is included, with instructions to add it.

        $modifiedSymbols = $this->getModifiedSymbols($symbols->getSymbols());

        $functionSymbols = array_filter($modifiedSymbols, fn(DiscoveredSymbol $symbol) => $symbol instanceof FunctionSymbol);
        $otherSymbols = array_filter($modifiedSymbols, fn(DiscoveredSymbol $symbol) => !($symbol instanceof FunctionSymbol));

        $targetDirClassmap = $this->getTargetClassmap();

        if (count($otherSymbols)>0) {
            $autoloadAliasesFileString .= 'function autoloadAliases( $classname ): void {' . PHP_EOL;
            $autoloadAliasesFileString = $this->appendAliasString($otherSymbols, $sourceDirClassmap, $targetDirClassmap, $autoloadAliasesFileString);
            $autoloadAliasesFileString .= '}' . PHP_EOL . PHP_EOL;
            $autoloadAliasesFileString .= "spl_autoload_register( 'autoloadAliases' );" . PHP_EOL . PHP_EOL;
        }

        if (count($functionSymbols)>0) {
            $autoloadAliasesFileString = $this->appendFunctionAliases($functionSymbols, $autoloadAliasesFileString);
        }

        return $autoloadAliasesFileString;
    }

    /**
     * @param array<NamespaceSymbol|ClassSymbol> $modifiedSymbols
     * @param array $sourceDirClassmap
     * @param array $targetDirClasssmap
     * @param string $autoloadAliasesFileString
     * @return string
     * @throws \League\Flysystem\FilesystemException
     */
    protected function appendAliasString(array $modifiedSymbols, array $sourceDirClassmap, array $targetDirClasssmap, string $autoloadAliasesFileString): string
    {
        $aliasesPhpString = '  switch( $classname ) {' . PHP_EOL;

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
                    $namespace = $symbol->getOriginalSymbol();

                    $symbolSourceFiles = $symbol->getSourceFiles();

                    $namespacesInOriginalClassmap = array_filter(
                        $sourceDirClassmap,
                        fn($filepath) => in_array($filepath, array_keys($symbolSourceFiles))
                    );

                    foreach ($namespacesInOriginalClassmap as $originalFqdnClassName => $absoluteFilePath) {
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

                            throw new \Exception("errorrrr " . ' ' . basename($b->getAbsoluteTargetPath()) . ' ' . $originalFqdnClassName . ' ' . $newFqdnClassName . PHP_EOL . PHP_EOL);
                        }

                        $symbolFilepath = $targetDirClasssmap[$newFqdnClassName] ?? $sourceDirClassmap[$originalFqdnClassName];
                        $symbolFileString = $this->fileSystem->read($symbolFilepath);

                        // This should be improved with a check for non-class-valid characters after the name.
                        // Eventually it should be in the File object itself.
                        $isClass = 1 === preg_match('/class ' . $localName . '/i', $symbolFileString);
                        $isInterface = 1 === preg_match('/interface ' . $localName . '/i', $symbolFileString);
                        $isTrait = 1 === preg_match('/trait ' . $localName . '/i', $symbolFileString);

                        if (!$isClass && !$isInterface && !$isTrait) {
                            $isEnum = 1 === preg_match('/enum ' . $localName . '/', $symbolFileString);

                            if ($isEnum) {
                                $this->logger->warning("Skipping $newFqdnClassName – enum aliasing not yet implemented.");
                                // TODO: enums
                                continue;
                            }

                            $this->logger->error("Skipping $newFqdnClassName because it doesn't exist.");
                            throw new \Exception("Skipping $newFqdnClassName because it doesn't exist.");
                        }

                        $escapedOriginalFqdnClassName = str_replace('\\', '\\\\', $originalFqdnClassName);
                        $aliasesPhpString .= "    case '$escapedOriginalFqdnClassName':" . PHP_EOL;

                        if ($isClass) {
                            $aliasesPhpString .= "      class_alias(\\$newFqdnClassName::class, \\$originalFqdnClassName::class);" . PHP_EOL;
                        } elseif ($isInterface) {
                            $aliasesPhpString .= "      \$includeFile = '<?php namespace $namespace; interface $localName extends \\$newFqdnClassName {};';" . PHP_EOL;
                            $aliasesPhpString .= "      include \"data://text/plain;base64,\" . base64_encode(\$includeFile);" . PHP_EOL;
                        } elseif ($isTrait) {
                            $aliasesPhpString .= "      \$includeFile = '<?php namespace $namespace; trait $localName { use \\$newFqdnClassName };';" . PHP_EOL;
                            $aliasesPhpString .= "      include \"data://text/plain;base64,\" . base64_encode(\$includeFile);" . PHP_EOL;
                        }

                        $aliasesPhpString .= "      break;" . PHP_EOL;
                    }
                    break;
                case ClassSymbol::class:
                    // TODO: Do we handle global traits or interfaces? at all?
                    $alias = $symbol->getOriginalSymbol(); // We want the original to continue to work, so it is the alias.
                    $concreteClass = $symbol->getReplacement();
                    $aliasesPhpString .= <<<EOD
    case '$alias':
      class_alias($concreteClass::class, $alias::class);
      break;
EOD;
                    break;

                default:
                    /**
                     * Functions and constants addressed below.
                     *
                     * @see self::appendFunctionAliases())
                     */
                    break;
            }
        }

        $autoloadAliasesFileString .= $aliasesPhpString;

        $autoloadAliasesFileString .= '    default:' . PHP_EOL;
        $autoloadAliasesFileString .= '      // Not in this autoloader.' . PHP_EOL;
        $autoloadAliasesFileString .= '      break;' . PHP_EOL;
        $autoloadAliasesFileString .= '  }' . PHP_EOL;

        return $autoloadAliasesFileString;
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
                    // TODO: check `function_exists()`
                    // Is it possible to inherit PHPDoc from the original function?
                    $aliasesPhpString = <<<EOD
        function $originalSymbol(...\$args) { return $replacementSymbol(func_get_args()); }
        
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
        if(defined('$originalSymbol')) { define('$replacementSymbol', $originalSymbol); }
        
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
