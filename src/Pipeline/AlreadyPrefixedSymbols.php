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
 * For each symbol to rename, from a package to prefix, whose namespace was already renamed, creates the symbol in the
 * original namespace, renamed to the existing name, e.g. `Psr\Log\LoggerInterface` renamed to
 * `Acme\Log\LoggerInterface`, so uses of the original name are updated.
 *
 * The original namespace is found by renaming forwards, not by reversing the renaming, so it works with
 * `namespace_replacement_patterns` (whose back-references cannot be reversed) as well as `namespace_prefix`: Strauss
 * does not edit a package's own `composer.json`, so the original namespaces in its `autoload` key are discovered, and
 * {@see ChangeEnumerator} renames them, e.g. `Psr\Log` to `Acme\Log`. A symbol in `Acme\Log`, or a namespace within
 * it, was already renamed from `Psr\Log`.
 *
 * Namespaces not in a `psr-4` or `psr-0` key, i.e. only autoloaded by `classmap` or `files`, are only recognised by
 * removing `namespace_prefix` from their start, so are not supported with `namespace_replacement_patterns`.
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

        // A previous run in another directory leaves the packages in `vendor` unprefixed.
        if (!$this->config->isTargetDirectoryVendor()) {
            return $originalSymbols;
        }

        $namespacePrefix = trim($this->config->getNamespacePrefix() ?? '', '\\');
        $packagesToPrefix = $this->config->getPackagesToPrefix();
        $namespaceRenames = $discoveredSymbols->getNamespaceRenames();

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

            // Its namespace is being renamed now: the package has not been renamed before.
            if ($prefixedSymbol->getNamespace()->getReplacementFqdnName() !== $prefixedNamespaceName) {
                continue;
            }

            $originalNamespaceName = $this->getOriginalNamespaceName($prefixedNamespaceName, $namespaceRenames, $namespacePrefix);

            if (is_null($originalNamespaceName)) {
                continue;
            }

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
     * @param string                         $prefixedNamespaceName The already renamed namespace, e.g. `Acme\Log\Test`.
     * @param array<string, NamespaceSymbol> $namespaceRenames      Replacement name => the original namespace, longest
     *                                                              first: {@see DiscoveredSymbols::getNamespaceRenames()}.
     * @param string                         $namespacePrefix       `namespace_prefix`, without slashes; empty if none.
     *
     * @return ?string The original namespace, e.g. `Psr\Log\Test`; null when it was not renamed.
     */
    protected function getOriginalNamespaceName(string $prefixedNamespaceName, array $namespaceRenames, string $namespacePrefix): ?string
    {
        foreach ($namespaceRenames as $replacementName => $originalNamespace) {
            if (
                $prefixedNamespaceName === $replacementName
                || str_starts_with($prefixedNamespaceName, $replacementName . '\\')
            ) {
                return $originalNamespace->getOriginalFqdnName() . substr($prefixedNamespaceName, strlen($replacementName));
            }
        }

        // E.g. a namespace only autoloaded by `classmap`, so its original name was not discovered.
        if ('' !== $namespacePrefix && str_starts_with($prefixedNamespaceName, $namespacePrefix . '\\')) {
            return substr($prefixedNamespaceName, strlen($namespacePrefix) + 1);
        }

        return null;
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
