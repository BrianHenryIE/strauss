<?php

/**
 * When prefixing in place, a package which was prefixed by an earlier run and not reinstalled since already declares
 * the prefixed names, so there is nothing left to say what the original names were. Packages installed or updated
 * since, e.g. after `composer update other/package`, still use the original names.
 *
 * @package brianhenryie/strauss
 * @see     https://github.com/BrianHenryIE/strauss/issues/343
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Config\AlreadyPrefixedSymbolsConfigInterface;
use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\EnumSymbol;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\InterfaceSymbol;
use BrianHenryIE\Strauss\Types\NamespacedSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use BrianHenryIE\Strauss\Types\TraitSymbol;
use LogicException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * For each symbol to rename, from a package to prefix, whose namespace already begins with `namespace_prefix`, creates
 * the symbol with the prefix removed, renamed to the existing name, e.g. `Psr\Log\LoggerInterface` renamed to
 * `Prefix\Psr\Log\LoggerInterface`, so uses of the original name are updated.
 *
 * Only used when planning changes, so no aliases etc. are created for them.
 */
class AlreadyPrefixedSymbols
{
    use LoggerAwareTrait;

    protected AlreadyPrefixedSymbolsConfigInterface $config;

    /**
     * @param AlreadyPrefixedSymbolsConfigInterface $config The namespace prefix and the packages to prefix.
     * @param ?LoggerInterface                      $logger Logs each original name found; null for none.
     */
    public function __construct(AlreadyPrefixedSymbolsConfigInterface $config, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->setLogger($logger ?? new NullLogger());
    }

    /**
     * @param DiscoveredSymbols $discoveredSymbols The symbols found, after their replacements have been determined.
     *
     * @return DiscoveredSymbols The original symbols, and their namespaces where not already discovered, to add when
     *                           planning changes.
     */
    public function getOriginalSymbols(DiscoveredSymbols $discoveredSymbols): DiscoveredSymbols
    {
        $originalSymbols = new DiscoveredSymbols();

        $namespacePrefix = trim($this->config->getNamespacePrefix() ?? '', '\\');

        // A previous run in another directory leaves the packages in `vendor` unprefixed.
        if (!$this->config->isTargetDirectoryVendor() || '' === $namespacePrefix) {
            return $originalSymbols;
        }

        $packagesToPrefix = $this->config->getPackagesToPrefix();

        $namespacedSymbols = array_merge(
            array_values($discoveredSymbols->getAllClasses()->toArray()),
            array_values($discoveredSymbols->getDiscoveredInterfaces()->toArray()),
            array_values($discoveredSymbols->getDiscoveredTraits()->toArray()),
            array_values($discoveredSymbols->getDiscoveredEnums()->toArray()),
            array_values($discoveredSymbols->getDiscoveredFunctions()->toArray()),
            array_values($discoveredSymbols->getConstants()->toArray())
        );

        foreach ($namespacedSymbols as $prefixedSymbol) {
            if (
                !($prefixedSymbol instanceof NamespacedSymbol)
                || !$prefixedSymbol->isDoRename()
                || $prefixedSymbol->getNamespace()->isGlobal()
                || !isset($packagesToPrefix[$prefixedSymbol->getPackageName() ?? ''])
            ) {
                continue;
            }

            $prefixedNamespaceName = $prefixedSymbol->getNamespace()->getOriginalFqdnName();

            if (!str_starts_with($prefixedNamespaceName, $namespacePrefix . '\\')) {
                continue;
            }

            $originalNamespaceName = substr($prefixedNamespaceName, strlen($namespacePrefix) + 1);
            $originalFqdnName = $originalNamespaceName . '\\' . $prefixedSymbol->getOriginalLocalName();

            // E.g. the package was reinstalled, so its original symbols were discovered too.
            if (!is_null($discoveredSymbols->get($originalFqdnName))) {
                continue;
            }

            $originalNamespace = $this->getOriginalNamespace(
                $originalNamespaceName,
                $prefixedSymbol,
                $discoveredSymbols,
                $originalSymbols
            );

            if (is_null($originalNamespace)) {
                continue;
            }

            $originalSymbol = $this->createOriginalSymbol($originalFqdnName, $prefixedSymbol, $originalNamespace);
            $originalSymbol->setDoRename(true);
            $originalSymbols->add($originalSymbol);

            $this->logger->info(
                'Already prefixed: {originalName} will be renamed to {prefixedName}',
                [
                    'originalName' => $originalFqdnName,
                    'prefixedName' => $prefixedSymbol->getOriginalFqdnName(),
                ]
            );
        }

        return $originalSymbols;
    }

    /**
     * The namespace of the original symbol, renamed to the prefixed symbol's namespace: the discovered one, e.g. from
     * the package's `autoload.psr-4` key, or a new one.
     *
     * @param string            $originalNamespaceName The namespace without the prefix.
     * @param NamespacedSymbol  $prefixedSymbol        The already prefixed symbol.
     * @param DiscoveredSymbols $discoveredSymbols     The symbols found.
     * @param DiscoveredSymbols $originalSymbols       The original symbols, and new namespaces, created so far.
     *
     * @return ?NamespaceSymbol Null when the discovered namespace is renamed to something else.
     */
    protected function getOriginalNamespace(
        string $originalNamespaceName,
        NamespacedSymbol $prefixedSymbol,
        DiscoveredSymbols $discoveredSymbols,
        DiscoveredSymbols $originalSymbols
    ): ?NamespaceSymbol {
        $prefixedNamespaceName = $prefixedSymbol->getNamespace()->getOriginalFqdnName();

        $originalNamespace = $discoveredSymbols->getNamespace($originalNamespaceName)
            ?? $originalSymbols->getNamespace($originalNamespaceName);

        if (is_null($originalNamespace)) {
            $originalNamespace = new NamespaceSymbol($originalNamespaceName, $this->getSourceFile($prefixedSymbol));
            $originalNamespace->setLocalReplacement($prefixedNamespaceName);
            $originalNamespace->setDoRename(true);
            $originalSymbols->add($originalNamespace);
            return $originalNamespace;
        }

        if ($originalNamespace->getReplacementFqdnName() !== $prefixedNamespaceName) {
            $this->logger->debug(
                'Not renaming already prefixed {originalName}: its namespace is renamed to {replacement}',
                [
                    'originalName' => $originalNamespaceName . '\\' . $prefixedSymbol->getOriginalLocalName(),
                    'replacement' => $originalNamespace->getReplacementFqdnName(),
                ]
            );
            return null;
        }

        return $originalNamespace;
    }

    /**
     * @param string           $originalFqdnName  The symbol's name without the prefix.
     * @param NamespacedSymbol $prefixedSymbol    The already prefixed symbol.
     * @param NamespaceSymbol  $originalNamespace The namespace without the prefix.
     */
    protected function createOriginalSymbol(
        string $originalFqdnName,
        NamespacedSymbol $prefixedSymbol,
        NamespaceSymbol $originalNamespace
    ): NamespacedSymbol {
        $sourceFile = $this->getSourceFile($prefixedSymbol);

        switch (true) {
            case $prefixedSymbol instanceof ClassSymbol:
                return new ClassSymbol($originalFqdnName, $sourceFile, $originalNamespace, $prefixedSymbol->isAbstract());
            case $prefixedSymbol instanceof InterfaceSymbol:
                return new InterfaceSymbol($originalFqdnName, $sourceFile, $originalNamespace);
            case $prefixedSymbol instanceof TraitSymbol:
                return new TraitSymbol($originalFqdnName, $sourceFile, $originalNamespace);
            case $prefixedSymbol instanceof EnumSymbol:
                return new EnumSymbol($originalFqdnName, $sourceFile, $originalNamespace, $prefixedSymbol->getBackingType());
            case $prefixedSymbol instanceof FunctionSymbol:
                return new FunctionSymbol($originalFqdnName, $sourceFile, $originalNamespace);
            default:
                return new ConstantSymbol($originalFqdnName, $sourceFile, $originalNamespace);
        }
    }

    /**
     * @param NamespacedSymbol $prefixedSymbol The already prefixed symbol, which was found in at least one file.
     */
    protected function getSourceFile(NamespacedSymbol $prefixedSymbol): FileBase
    {
        $sourceFiles = $prefixedSymbol->getSourceFiles();
        $sourceFile = reset($sourceFiles);

        if (false === $sourceFile) {
            throw new LogicException('A discovered symbol has no source file.');
        }

        return $sourceFile;
    }
}
