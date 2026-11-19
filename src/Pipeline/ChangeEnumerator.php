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
use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespacedSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use BrianHenryIE\Strauss\Types\Psr0NamespaceSymbol;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * Works out which {@see DiscoveredSymbols} entries marked for renaming were already renamed, e.g. by an earlier run in
 * place, and their names before it: their inverse replacements. Those keep their names. The others get their local
 * replacement: namespaces via the first matching `namespace_replacement_patterns` regex, else `namespace_prefix`;
 * namespaced classes, interfaces, traits and enums by swapping in their namespace's replacement; global ones with
 * `classmap_prefix`; global functions with `functions_prefix` (handling PSR-0 underscored names); and constants with
 * `constants_prefix`, all read from the {@see ChangeEnumeratorConfigInterface}.
 */
class ChangeEnumerator
{
    use LoggerAwareTrait;

    protected ChangeEnumeratorConfigInterface $config;

    /**
     * @var ?array<string, string> Built on first use: {@see self::getNamespaceReplacementPatterns()}.
     */
    protected ?array $namespaceReplacementPatterns = null;

    public function __construct(
        ChangeEnumeratorConfigInterface $config,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->setLogger($logger);
    }

    /**
     * First works out which symbols were already renamed by an earlier run, and their names before it: their inverse
     * replacements. Those are not changed. The others get their replacements.
     *
     * @param DiscoveredSymbols $discoveredSymbols The symbols, marked for renaming or not.
     */
    public function determineReplacements(DiscoveredSymbols $discoveredSymbols): void
    {
        $classmapPrefix = $this->config->getClassmapPrefix() ?? '';
        $functionsPrefix = $this->config->getFunctionsPrefix() ?? '';
        $constantsPrefix = $this->config->getConstantsPrefix() ?? '';

        $namespaces = $this->getNamespacesToRename($discoveredSymbols);
        $classes = $this->getGlobalClassesInterfacesTraitsEnums($discoveredSymbols);
        $functions = $this->getGlobalSymbols($discoveredSymbols->getDiscoveredFunctions()->toArray());
        $constants = $this->getGlobalSymbols($discoveredSymbols->getConstants()->toArray());

        $this->determineNamespaceInverseReplacements($namespaces);
        $this->determineGlobalInverseReplacements($classes, $classmapPrefix);
        $this->determineGlobalInverseReplacements($functions, $functionsPrefix);
        $this->determineGlobalInverseReplacements($constants, $constantsPrefix);

        foreach ($namespaces as $namespace) {
            if (!is_null($namespace->getInverseReplacement())) {
                continue;
            }
            $replacement = $this->getNamespaceReplacement($namespace->getOriginalFqdnName());
            if (is_null($replacement)) {
                $this->logger->debug('Namespace {namespace} not changed.', ['namespace' => $namespace->getOriginalFqdnName()]);
                continue;
            }
            $namespace->setLocalReplacement($replacement);
        }

        $this->determineNamespacedClassReplacements($discoveredSymbols);

        if ('' !== $classmapPrefix) {
            foreach ($classes as $symbol) {
                if (is_null($symbol->getInverseReplacement())) {
                    $symbol->setLocalReplacement($classmapPrefix . $symbol->getOriginalFqdnName());
                }
            }
        }

        $excludeFunctionsFromStringRenaming = $this->config->getExcludeFunctionsFromStringRenaming();
        foreach ($functions as $symbol) {
            // Common words, e.g. `value`, should still be prefixed where called, but not inside strings.
            if (in_array($symbol->getOriginalFqdnName(), $excludeFunctionsFromStringRenaming, true)) {
                $symbol->setReplaceInString(false);
            }
            if ('' !== $functionsPrefix && is_null($symbol->getInverseReplacement())) {
                $this->globalOrPsr0($symbol, $functionsPrefix, $discoveredSymbols);
            }
        }

        if ('' !== $constantsPrefix) {
            /**
 * @var NamespacedSymbol $symbol
*/
            foreach ($discoveredSymbols->getConstants() as $symbol) {
                if (is_null($symbol->getInverseReplacement())) {
                    $symbol->setLocalReplacement($constantsPrefix . $symbol->getOriginalLocalName());
                }
            }
        }
    }

    /**
     * The namespaces to rename, after removing those in `exclude_from_prefix.namespaces`.
     *
     * @param DiscoveredSymbols $discoveredSymbols All the discovered symbols.
     *
     * @return NamespaceSymbol[]
     */
    protected function getNamespacesToRename(DiscoveredSymbols $discoveredSymbols): array
    {
        $namespaces = [];
        foreach ($discoveredSymbols->getNamespaces() as $namespace) {
            if (!($namespace instanceof NamespaceSymbol) || !$namespace->isDoRename() || $namespace->isGlobal()) {
                continue;
            }
            if (in_array($namespace->getOriginalFqdnName(), $this->config->getExcludeNamespacesFromPrefixing(), true)) {
                $namespace->setDoRename(false);
                continue;
            }
            $namespaces[] = $namespace;
        }
        return $namespaces;
    }

    /**
     * @param DiscoveredSymbols $discoveredSymbols All the discovered symbols.
     *
     * @return NamespacedSymbol[]
     */
    protected function getGlobalClassesInterfacesTraitsEnums(DiscoveredSymbols $discoveredSymbols): array
    {
        return $this->getGlobalSymbols(
            array_merge(
                $discoveredSymbols->getDiscoveredTraits()->toArray(),
                $discoveredSymbols->getDiscoveredInterfaces()->toArray(),
                $discoveredSymbols->getDiscoveredEnums()->toArray(),
                $discoveredSymbols->getAllClasses()->toArray()
            )
        );
    }

    /**
     * @param DiscoveredSymbol[] $symbols Classes, functions, constants etc.
     *
     * @return NamespacedSymbol[] Those in the global namespace.
     */
    protected function getGlobalSymbols(array $symbols): array
    {
        return array_values(
            array_filter(
                $symbols,
                fn(DiscoveredSymbol $symbol): bool => $symbol instanceof NamespacedSymbol && $symbol->isGlobal()
            )
        );
    }

    /**
     * `namespace_replacement_patterns`, as regular expressions, in order of priority.
     *
     * @return array<string, string> Pattern => replacement.
     */
    protected function getNamespaceReplacementPatterns(): array
    {
        if (is_null($this->namespaceReplacementPatterns)) {
            $this->namespaceReplacementPatterns = [];
            // TODO: Maybe need to preg_quote and add regex delimiters to the patterns here.
            foreach ($this->config->getNamespaceReplacementPatterns() as $pattern => $replacement) {
                if (substr($pattern, 0, 1) !== substr($pattern, - 1, 1)) {
                    $pattern = '~' . preg_quote($pattern, '~') . '~';
                }
                $this->namespaceReplacementPatterns[$pattern] = $replacement;
            }
        }
        return $this->namespaceReplacementPatterns;
    }

    /**
     * The name given by the first `namespace_replacement_patterns` pattern which changes it.
     *
     * @param string $namespaceName A namespace's name.
     */
    protected function getPatternReplacement(string $namespaceName): ?string
    {
        foreach ($this->getNamespaceReplacementPatterns() as $pattern => $replacement) {
            $replaced = preg_replace($pattern, $replacement, $namespaceName) ?? (function () {
                throw new \Exception(preg_last_error_msg(), preg_last_error());
            })();
            if ($replaced !== $namespaceName) {
                return $replaced;
            }
        }
        return null;
    }

    /**
     * The name a namespace is renamed to: by the first matching `namespace_replacement_patterns` pattern, else with
     * `namespace_prefix`.
     *
     * @param string $namespaceName A namespace's name.
     *
     * @return ?string Null when it is not renamed.
     */
    protected function getNamespaceReplacement(string $namespaceName): ?string
    {
        $patternReplacement = $this->getPatternReplacement($namespaceName);
        if (!is_null($patternReplacement)) {
            return $patternReplacement;
        }

        // This is being done elsewhere but is probably more appropriate here,
        // I'm worried if I change it now it will affect the directory structure.
        //                    if ($symbol instanceof Psr0NamespaceSymbol) {
        //                        $namespacePrefix = str_replace('\\', '_', rtrim($namespacePrefix, '\\')).'_' ;
        //                    }

        $namespacePrefix = $this->getNamespacePrefix();

        return '' === $namespacePrefix ? null : $namespacePrefix . '\\' . $namespaceName;
    }

    /**
     * `namespace_prefix`, without leading or trailing backslashes; empty when not set.
     */
    protected function getNamespacePrefix(): string
    {
        return trim($this->config->getNamespacePrefix() ?? '', '\\');
    }

    /**
     * A namespace already renamed by an earlier run, e.g. when prefixing in place and the package was not reinstalled
     * since, gets its name before that run:
     *
     * * When it is, or is inside, the name another discovered namespace is renamed to, e.g. `Acme\Log` when `Psr\Log`,
     *   from psr/log's `autoload` key, is renamed to it. Found by renaming forwards, so it works with patterns whose
     *   back-references can't be reversed.
     * * Otherwise, when no pattern renames it and it begins with `namespace_prefix`, by removing the prefix, e.g. for a
     *   namespace only autoloaded by `classmap`.
     *
     * @param NamespaceSymbol[] $namespaces The namespaces to rename.
     *
     * @see DiscoveredSymbols::getInverseReplacementSymbols()
     * @see https://github.com/BrianHenryIE/strauss/issues/34
     * @see https://github.com/BrianHenryIE/strauss/issues/343
     */
    protected function determineNamespaceInverseReplacements(array $namespaces): void
    {
        if (0 === count($namespaces)) {
            return;
        }

        /**
 * @var array<string, NamespaceSymbol> $renames Replacement name => the namespace renamed to it.
*/
        $renames = [];
        foreach ($namespaces as $namespace) {
            $replacement = $this->getNamespaceReplacement($namespace->getOriginalFqdnName());
            if (!is_null($replacement) && $replacement !== $namespace->getOriginalFqdnName()) {
                $renames[$replacement] = $namespace;
            }
        }
        // Longest first, so the first match of a name or its parent namespace is the most specific.
        uksort($renames, fn(string $a, string $b): int => strlen($b) - strlen($a));

        $namespacePrefix = $this->getNamespacePrefix();

        foreach ($namespaces as $namespace) {
            $namespaceName = $namespace->getOriginalFqdnName();

            $inverseReplacement = null;
            foreach ($renames as $replacementName => $originalNamespace) {
                if (
                    $originalNamespace !== $namespace
                    && ($namespaceName === $replacementName || str_starts_with($namespaceName, $replacementName . '\\'))
                ) {
                    $inverseReplacement = $originalNamespace->getOriginalFqdnName() . substr($namespaceName, strlen($replacementName));
                    break;
                }
            }

            if (is_null($inverseReplacement) && '' !== $namespacePrefix && is_null($this->getPatternReplacement($namespaceName))) {
                $inverseReplacement = $this->getPrefixedFrom($namespaceName, $namespacePrefix . '\\');
            }

            if (!is_null($inverseReplacement)) {
                $this->logger->debug(
                    'Namespace {namespace} is already renamed from {inverseReplacement}, not renaming it again.',
                    ['namespace' => $namespaceName, 'inverseReplacement' => $inverseReplacement]
                );
                $namespace->setInverseReplacement($inverseReplacement);
            }
        }
    }

    /**
     * A global class, function or constant which already begins with its prefix, e.g. prefixed by an earlier run,
     * gets its name without it.
     *
     * @param NamespacedSymbol[] $symbols Global symbols.
     * @param string             $prefix  The `classmap_prefix`, `functions_prefix` or `constants_prefix`; empty
     *                                    when not set.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/258
     */
    protected function determineGlobalInverseReplacements(array $symbols, string $prefix): void
    {
        foreach ($symbols as $symbol) {
            $inverseReplacement = $this->getPrefixedFrom($symbol->getOriginalFqdnName(), $prefix);
            if (!is_null($inverseReplacement)) {
                $this->logger->debug(
                    '{symbol} is already prefixed, not prefixing it again.',
                    ['symbol' => $symbol->getOriginalFqdnName()]
                );
                $symbol->setInverseReplacement($inverseReplacement);
            }
        }
    }

    /**
     * @param string $name   A symbol's name.
     * @param string $prefix The prefix it may begin with; empty when not set.
     *
     * @return ?string The name without the prefix, or null when it doesn't begin with it.
     */
    protected function getPrefixedFrom(string $name, string $prefix): ?string
    {
        return '' !== $prefix && $name !== $prefix && str_starts_with($name, $prefix)
            ? substr_replace($name, '', 0, strlen($prefix))
            : null;
    }

    /**
     * Namespaced classes, interfaces, traits and enums take their namespace's replacement.
     *
     * @param DiscoveredSymbols $discoveredSymbols All the discovered symbols.
     */
    protected function determineNamespacedClassReplacements(DiscoveredSymbols $discoveredSymbols): void
    {
        $discoveredNamespaces = $discoveredSymbols->getNamespaces();

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
            if ($symbol->getNamespace()->isGlobal() || !$discoveredNamespaces->has($symbol->getNamespace())) {
                continue;
            }
            /**
 * @var NamespaceSymbol $newNamespace
*/
            $newNamespace = $discoveredNamespaces->get($symbol->getNamespace()->getOriginalFqdnName());
            $symbol->setLocalReplacement(
                $this->determineNamespaceReplacement(
                    $newNamespace->getOriginalFqdnName(),
                    $newNamespace->getReplacementFqdnName(),
                    $symbol->getOriginalFqdnName()
                )
            );
        }
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
