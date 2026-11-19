<?php

/**
 * Determine the replacements to be made to the discovered symbols.
 *
 * Typically, this will just be a prefix, but more complex rules allow for replacements specific to individual symbols/namespaces.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Config\ChangeEnumeratorConfigInterface;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\NamespacedSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use BrianHenryIE\Strauss\Types\Psr0NamespaceSymbol;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * Sets the local replacement on each {@see DiscoveredSymbols} entry marked for renaming: namespaces via the first
 * matching `namespace_replacement_patterns` regex, else `namespace_prefix`; namespaced classes, interfaces, traits and enums by swapping in their namespace's replacement;
 * global ones with `classmap_prefix`; global functions with `function_prefix` (handling PSR-0 underscored names);
 * and constants with `constant_prefix`, all read from the {@see ChangeEnumeratorConfigInterface}.
 */
class ChangeEnumerator
{
    use LoggerAwareTrait;

    protected ChangeEnumeratorConfigInterface $config;

    public function __construct(
        ChangeEnumeratorConfigInterface $config,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->setLogger($logger);
    }

    public function determineReplacements(DiscoveredSymbols $discoveredSymbols): void
    {
        $discoveredNamespaces = $discoveredSymbols->getNamespaces();

        foreach ($discoveredNamespaces as $symbol) {
            if (!$symbol->isDoRename()) {
                continue;
            }

            // This line seems redundant.
            if ($symbol instanceof NamespaceSymbol) {
                $namespaceReplacementPatterns = $this->config->getNamespaceReplacementPatterns();

                if (
                    in_array(
                        $symbol->getOriginalFqdnName(),
                        $this->config->getExcludeNamespacesFromPrefixing(),
                        true
                    )
                ) {
                    $symbol->setDoRename(false);
                }

                // TODO: Maybe need to preg_quote and add regex delimiters to the patterns here.
                foreach ($namespaceReplacementPatterns as $pattern => $replacement) {
                    if (substr($pattern, 0, 1) !== substr($pattern, - 1, 1)) {
                        unset($namespaceReplacementPatterns[ $pattern ]);
                        $pattern                                  = '~' . preg_quote($pattern, '~') . '~';
                        $namespaceReplacementPatterns[ $pattern ] = $replacement;
                    }
                    unset($pattern, $replacement);
                }

                // `namespace_replacement_patterns` should be ordered by priority.
                foreach ($namespaceReplacementPatterns as $namespaceReplacementPattern => $replacement) {
                    $prefixed = preg_replace(
                        $namespaceReplacementPattern,
                        $replacement,
                        $symbol->getOriginalFqdnName()
                    ) ?? (function () {
                        throw new \Exception(preg_last_error_msg(), preg_last_error());
                    })();

                    if ($prefixed !== $symbol->getOriginalFqdnName()) {
                        $symbol->setLocalReplacement($prefixed);
                        continue 2;
                    }
                }

                // `namespace_prefix` is a catch-all after the patterns.
                $namespacePrefix = trim($this->config->getNamespacePrefix() ?? '', '\\');
                if ('' === $namespacePrefix) {
                    $this->logger->debug("Namespace {$symbol->getOriginalFqdnName()} not changed.");
                    continue;
                }

                // This is being done elsewhere but is probably more appropriate here,
                // I'm worried if I change it now it will affect the directory structure.
                //                    if ($symbol instanceof Psr0NamespaceSymbol) {
                //                        $namespacePrefix = str_replace('\\', '_', rtrim($namespacePrefix, '\\')).'_' ;
                //                    }

                if (!$this->keepAlreadyPrefixedNamespace($symbol, $namespacePrefix)) {
                    $symbol->setLocalReplacement($namespacePrefix . '\\' . $symbol->getOriginalFqdnName());
                }
            }
        }

        $this->keepAlreadyRenamedNamespaces($discoveredSymbols);

        $classmapPrefix = $this->config->getClassmapPrefix();

        $classesTraitsInterfaces = array_merge(
            $discoveredSymbols->getDiscoveredTraits()->toArray(),
            $discoveredSymbols->getDiscoveredInterfaces()->toArray(),
            $discoveredSymbols->getDiscoveredEnums()->toArray(),
            $discoveredSymbols->getAllClasses()->toArray()
        );

        /**
 * @var NamespacedSymbol $symbol
*/
        foreach ($classesTraitsInterfaces as $symbol) {
            // If we're a namespaced class/interface/trait, apply the fqdnchange.
            if (!$symbol->getNamespace()->isGlobal()) {
                if ($discoveredNamespaces->has($symbol->getNamespace())) {
                    //                if (isset($discoveredNamespaces[ $symbol->getNamespace() ])) {
                    /**
 * @var NamespaceSymbol $newNamespace
*/
                    $newNamespace = $discoveredNamespaces->get($symbol->getNamespace()->getOriginalFqdnName());
                    $replacement  = $this->determineNamespaceReplacement(
                        $newNamespace->getOriginalFqdnName(),
                        $newNamespace->getReplacementFqdnName(),
                        $symbol->getOriginalFqdnName()
                    );

                    $symbol->setLocalReplacement($replacement);

                    unset($newNamespace, $replacement);
                }
                continue;
            }

            // If there is no global prefix to set, continue.
            if (!$classmapPrefix) {
                continue;
            }

            // If the classname/interfacename/traitname already appears to begin with the prefix, skip.
            if ($this->keepAlreadyPrefixedGlobalSymbol($symbol, $classmapPrefix)) {
                continue;
            }

            // Prefix global class.
            $replacement = $classmapPrefix . $symbol->getOriginalFqdnName();
            $symbol->setLocalReplacement($replacement);
        }
        unset($classmapPrefix, $classesTraitsInterfaces);

        $functionsSymbols = $discoveredSymbols->getDiscoveredFunctions();
        $excludeFunctionsFromStringRenaming = $this->config->getExcludeFunctionsFromStringRenaming();

        /**
 * @var FunctionSymbol $symbol
*/
        foreach ($functionsSymbols as $symbol) {
            // Don't prefix functions in a namespace – that will be addressed by the namespace prefix.
            if (!$symbol->getNamespace()->isGlobal()) {
                continue;
            }
            // Common words, e.g. `value`, should still be prefixed where called, but not inside strings.
            if (in_array($symbol->getOriginalFqdnName(), $excludeFunctionsFromStringRenaming, true)) {
                $symbol->setReplaceInString(false);
            }
            $functionPrefix = $this->config->getFunctionsPrefix();
            if (empty($functionPrefix) || $this->keepAlreadyPrefixedGlobalSymbol($symbol, $functionPrefix)) {
                continue;
            }
            $this->globalOrPsr0($symbol, $functionPrefix, $discoveredSymbols);

            unset($symbol);
        }
        unset($functionsSymbols);

        if ($this->config->getConstantsPrefix()) {
            $constantPrefix = $this->config->getConstantsPrefix();
            /**
 * @var NamespacedSymbol $constantSymbol
*/
            foreach ($discoveredSymbols->getConstants() as $constantSymbol) {
                if ($constantSymbol->isGlobal() && $this->keepAlreadyPrefixedGlobalSymbol($constantSymbol, $constantPrefix)) {
                    continue;
                }
                $constantSymbol->setLocalReplacement($constantPrefix . $constantSymbol->getOriginalLocalName());
            }
        }
    }

    /**
     * Is the namespace already prefixed, e.g. by an earlier run in place? It then keeps its name, and when prefixing in
     * place, its name before the earlier run is kept so uses of that name are updated. This covers namespaces not in
     * their package's `autoload` key, e.g. only autoloaded by `classmap`, which
     * {@see self::keepAlreadyRenamedNamespaces()} can't find.
     *
     * @param NamespaceSymbol $namespace       A namespace not matched by a `namespace_replacement_patterns` pattern.
     * @param string          $namespacePrefix The `namespace_prefix`, without leading or trailing backslashes.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/34
     */
    protected function keepAlreadyPrefixedNamespace(NamespaceSymbol $namespace, string $namespacePrefix): bool
    {
        $namespaceName = $namespace->getOriginalFqdnName();

        if (!str_starts_with($namespaceName, $namespacePrefix . '\\')) {
            return false;
        }

        $this->logger->debug('Namespace {namespace} is already prefixed, not prefixing again.', ['namespace' => $namespaceName]);

        $namespace->setLocalReplacement($namespaceName);
        if ($this->config->isTargetDirectoryVendor()) {
            $namespace->setInverseReplacement(substr($namespaceName, strlen($namespacePrefix) + 1));
        }

        return true;
    }

    /**
     * Is the global class, function or constant already prefixed, e.g. by an earlier run in place? It then keeps its
     * name, and when prefixing in place, its name before the earlier run is kept so uses of that name, e.g. in packages
     * installed since, are updated. See {@see DiscoveredSymbols::getInverseReplacementSymbols()}.
     *
     * @param NamespacedSymbol $symbol A global symbol.
     * @param string           $prefix The `classmap_prefix`, `functions_prefix` or `constants_prefix`.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/258
     * @see https://github.com/BrianHenryIE/strauss/issues/343
     */
    protected function keepAlreadyPrefixedGlobalSymbol(NamespacedSymbol $symbol, string $prefix): bool
    {
        $originalName = $symbol->getOriginalFqdnName();

        if (!str_starts_with($originalName, $prefix) || $originalName === $prefix) {
            return false;
        }

        $this->logger->debug('{symbol} is already prefixed, not prefixing again.', ['symbol' => $originalName]);

        if ($this->config->isTargetDirectoryVendor()) {
            $symbol->setInverseReplacement(substr($originalName, strlen($prefix)));
        }

        return true;
    }

    protected function globalOrPsr0(NamespacedSymbol $symbol, string $globalPrefix, DiscoveredSymbols $discoveredSymbols): void
    {
        if ($symbol->isPsr0Autoloaded()) {
            /**
             * @var            Psr0NamespaceSymbol $psr0Namespace
             *
             * `::isPsr0Autoloaded()` proves it is not null.
             * @phpstan-ignore argument.type
             */
            $psr0Namespace = $discoveredSymbols->getNamespace($symbol->getPsr0NamespaceString());

            $underscoredOriginalNamespace = str_replace('\\', '_', $psr0Namespace->getOriginalLocalName());
            $underscoredNewNamespace = str_replace('\\', '_', $psr0Namespace->getReplacementFqdnName());

            $classnameParts = explode('_', $symbol->getOriginalFqdnName());
            $classname = array_pop($classnameParts);
            $originalNamespace = implode('_', $classnameParts);

            // Still global
            if (empty($originalNamespace)) {
                $replacement = $globalPrefix . $symbol->getOriginalFqdnName();
                $symbol->setLocalReplacement($replacement);
            } else {
                $unnamespacedClass = preg_replace('#^' . $underscoredOriginalNamespace . '#', '', $symbol->getOriginalFqdnName());
                $replacementPsr0Classname = trim($underscoredNewNamespace . $unnamespacedClass, '_');
                $symbol->setLocalReplacement($replacementPsr0Classname);
            }
        } else {
            $replacement = $globalPrefix . ltrim($symbol->getOriginalFqdnName(), '\\');
            $symbol->setLocalReplacement($replacement);
        }
    }

    /**
     * When prefixing in place, a package renamed by an earlier run and not reinstalled since already declares the
     * renamed namespace, e.g. `Acme\Log`, while its `autoload` key still gives the original, `Psr\Log`, which is
     * renamed to it. The renamed namespace, and those within it, must not be renamed again, e.g. by the default
     * `namespace_prefix` when a `namespace_replacement_patterns` pattern no longer matches it.
     *
     * Their names before renaming, found by renaming forwards, so it works with patterns whose back-references can't
     * be reversed, are kept, so uses of them, e.g. in packages installed since, are updated: e.g. `Acme\Log\Test` was
     * `Psr\Log\Test`. See {@see DiscoveredSymbols::getInverseReplacementSymbols()}.
     *
     * @param DiscoveredSymbols $discoveredSymbols All discovered symbols, after the namespaces' replacements are set.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/343
     */
    protected function keepAlreadyRenamedNamespaces(DiscoveredSymbols $discoveredSymbols): void
    {
        $namespaceRenames = $discoveredSymbols->getNamespaceRenames();

        foreach ($discoveredSymbols->getNamespaces() as $namespace) {
            if (!($namespace instanceof NamespaceSymbol) || !$namespace->isDoRename() || $namespace->isGlobal()) {
                continue;
            }

            $namespaceName = $namespace->getOriginalFqdnName();

            foreach ($namespaceRenames as $replacementName => $originalNamespace) {
                if ($originalNamespace === $namespace) {
                    continue;
                }

                if ($namespaceName === $replacementName || str_starts_with($namespaceName, $replacementName . '\\')) {
                    if ($namespace->getReplacementFqdnName() !== $namespaceName) {
                        $this->logger->debug(
                            'Namespace {namespace} is already renamed from {originalNamespace}, not renaming to {replacement}.',
                            [
                                'namespace' => $namespaceName,
                                'originalNamespace' => $originalNamespace->getOriginalFqdnName(),
                                'replacement' => $namespace->getReplacementFqdnName(),
                            ]
                        );
                    }
                    $namespace->setLocalReplacement($namespaceName);
                    if ($this->config->isTargetDirectoryVendor()) {
                        $namespace->setInverseReplacement(
                            $originalNamespace->getOriginalFqdnName() . substr($namespaceName, strlen($replacementName))
                        );
                    }
                    continue 2;
                }
            }
        }
    }

    /**
     * `str_replace` was replacing multiple. This stops after one. Maybe should be tied to start of string.
     *
     * @param string $originalNamespace The namespace being replaced, without leading backslash.
     * @param string $newNamespace      The prefixed namespace to replace it with.
     * @param string $fqdnClassname     The class's fully qualified name, without leading backslash.
     */
    protected function determineNamespaceReplacement(string $originalNamespace, string $newNamespace, string $fqdnClassname): string
    {
        $search = '/' . preg_quote($originalNamespace, '/') . '/';

        return preg_replace($search, $newNamespace, $fqdnClassname, 1)
                ?? (function () {
                    throw new \Exception(preg_last_error_msg(), preg_last_error());
                })();
    }
}
