<?php
/**
 * Determine the replacements to be made to the discovered symbols.
 *
 * Typically, this will just be a prefix, but more complex rules allow for replacements specific to individual symbols/namespaces.
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Config\ChangeEnumeratorConfigInterface;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespacedSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

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
        $discoveredNamespaces = $discoveredSymbols->getDiscoveredNamespaces();

        foreach ($discoveredNamespaces as $symbol) {
            if (!$symbol->isDoRename()) {
                continue;
            }

            // This line seems redundant.
            if ($symbol instanceof NamespaceSymbol) {
                $namespaceReplacementPatterns = $this->config->getNamespaceReplacementPatterns();

                if (in_array(
                    $symbol->getOriginalSymbol(),
                    $this->config->getExcludeNamespacesFromPrefixing(),
                    true
                )) {
                    $symbol->setDoRename(false);
                }

                // `namespace_prefix` is just a shorthand for a replacement pattern that applies to all namespaces.

                // TODO: Maybe need to preg_quote and add regex delimiters to the patterns here.
                foreach ($namespaceReplacementPatterns as $pattern => $replacement) {
                    if (substr($pattern, 0, 1) !== substr($pattern, - 1, 1)) {
                        unset($namespaceReplacementPatterns[ $pattern ]);
                        $pattern                                  = '~' . preg_quote($pattern, '~') . '~';
                        $namespaceReplacementPatterns[ $pattern ] = $replacement;
                    }
                    unset($pattern, $replacement);
                }

                if (! is_null($this->config->getNamespacePrefix())) {
                    $stripPattern   = '~^(' . preg_quote($this->config->getNamespacePrefix(), '~') . '\\\\*)*(.*)~';
                    $strippedSymbol = preg_replace(
                        $stripPattern,
                        '$2',
                        $symbol->getOriginalSymbol()
                    );
                    $namespaceReplacementPatterns[ "~(" . preg_quote($this->config->getNamespacePrefix(), '~') . '\\\\*)*' . preg_quote($strippedSymbol, '~') . '~' ]
                                    = "{$this->config->getNamespacePrefix()}\\{$strippedSymbol}";
                    unset($stripPattern, $strippedSymbol);
                }

                // `namespace_replacement_patterns` should be ordered by priority.
                foreach ($namespaceReplacementPatterns as $namespaceReplacementPattern => $replacement) {
                    $prefixed = preg_replace(
                        $namespaceReplacementPattern,
                        $replacement,
                        $symbol->getOriginalSymbol()
                    );

                    if ($prefixed !== $symbol->getOriginalSymbol()) {
                        $symbol->setLocalReplacement($prefixed);
                        continue 2;
                    }
                }
                $this->logger->debug("Namespace {$symbol->getOriginalSymbol()} not changed.");
            }
        }

        $classmapPrefix = $this->config->getClassmapPrefix();


        $classesTraitsInterfaces = array_merge(
            $discoveredSymbols->getDiscoveredTraits()->toArray(),
            $discoveredSymbols->getDiscoveredInterfaces()->toArray(),
            $discoveredSymbols->getAllClasses()->toArray()
        );

        foreach ($classesTraitsInterfaces as $symbol) {
            if (str_starts_with($symbol->getOriginalSymbol(), $classmapPrefix)) {
                // Already prefixed / second scan.
                continue;
            }

            if (!$symbol->isDoRename()) {
                continue;
            }

            // If we're a namespaced class, apply the fqdnchange.
            if (!$symbol->getNamespace()->isGlobal()) {
                if (isset($discoveredNamespaces[$symbol->getNamespaceName()])) {
                    $newNamespace = $discoveredNamespaces[$symbol->getNamespaceName()];
                    $replacement = $this->determineNamespaceReplacement(
                        $newNamespace->getOriginalSymbol(),
                        $newNamespace->getLocalReplacement(),
                        $symbol->getOriginalSymbol()
                    );

                    $symbol->setLocalReplacement($replacement);

                    unset($newNamespace, $replacement);
                }
                continue;
            } else {
                // Global class.
                // Don't double-prefix classnames.
                if (str_starts_with($symbol->getOriginalSymbol(), $this->config->getClassmapPrefix())) {
                    continue;
                }

                $this->globalOrPsr0($symbol, $classmapPrefix, $discoveredSymbols);
            }
        }

        $functionsSymbols = $discoveredSymbols->getDiscoveredFunctions();

        foreach ($functionsSymbols as $symbol) {
            // Don't prefix functions in a namespace – that will be addressed by the namespace prefix.
            if (!$symbol->getNamespace()->isGlobal()) {
                continue;
            }
            if (empty($functionPrefix) || str_starts_with($symbol->getOriginalSymbol(), $functionPrefix)) {
                continue;
            }
            $this->globalOrPsr0($symbol, $this->config->getFunctionsPrefix(), $discoveredSymbols);
        }
    }

    protected function globalOrPsr0(NamespacedSymbol $symbol, string $globalPrefix, DiscoveredSymbols $discoveredSymbols): void
    {

        if ($symbol->isPsr0Autoloaded()) {
            $psr0Namespace = $discoveredSymbols->getNamespace($symbol->getPsr0NamespaceString());

            $underscoredOriginalNamespace = str_replace('\\', '_', $psr0Namespace->getOriginalLocalName());
            $underscoredNewNamespace = str_replace('\\', '_', $psr0Namespace->getReplacementFqdnName());

            $classnameParts = explode('_', $symbol->getOriginalSymbol());
            $classname = array_pop($classnameParts);
            $originalNamespace = implode('_', $classnameParts);

            // Still global
            if (empty($originalNamespace)) {
                $replacement = $globalPrefix . $symbol->getOriginalSymbol();
                $symbol->setLocalReplacement($replacement);
            } else {
                $unnamespacedClass = preg_replace('#^' . $underscoredOriginalNamespace . '#', '', $symbol->getOriginalSymbol());
                $replacementPsr0Classname = trim($underscoredNewNamespace . $unnamespacedClass, '_');
                $symbol->setLocalReplacement($replacementPsr0Classname);
            }
        } else {
            $replacement = $globalPrefix . $symbol->getOriginalSymbol();
            $symbol->setLocalReplacement($replacement);
        }
    }

    /**
     *`str_replace` was replacing multiple. This stops after one. Maybe should be tied to start of string.
     */
    protected function determineNamespaceReplacement(string $originalNamespace, string $newNamespace, string $fqdnClassname): string
    {
        $search = '/' . preg_quote($originalNamespace, '/') . '/';

        return preg_replace($search, $newNamespace, $fqdnClassname, 1);
    }
}
