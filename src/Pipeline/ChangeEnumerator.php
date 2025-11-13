<?php
/**
 * Determine the replacements to be made to the discovered symbols.
 *
 * Typically, this will just be a prefix, but more complex rules allow for replacements specific to individual symbols/namespaces.
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Config\ChangeEnumeratorConfigInterface;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
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

                // If any of the files the symbol was found in are marked not to prefix, don't prefix the symbol.
                if ($symbol->getNamespace() !== '\\' && !array_reduce($symbol->getSourceFiles(), fn(bool $carry, File $file) => $carry && $file->isDoPrefix(), true)) {
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
                        $symbol->setReplacement($prefixed);
                        continue 2;
                    }
                }
                $this->logger->debug("Namespace {$symbol->getOriginalSymbol()} not changed.");
            }
        }

        $classmapPrefix = $this->config->getClassmapPrefix();


        $classesTraitsInterfaces = array_merge(
            $discoveredSymbols->getDiscoveredTraits(),
            $discoveredSymbols->getDiscoveredInterfaces(),
            $discoveredSymbols->getAllClasses()
        );

        foreach ($classesTraitsInterfaces as $symbol) {
            if (str_starts_with($symbol->getOriginalSymbol(), $classmapPrefix)) {
                // Already prefixed / second scan.
                continue;
            }

            if ($symbol->getNamespace() === '\\') {
                if ($symbol instanceof ClassSymbol) {
                    // Don't double-prefix classnames.
                    if (str_starts_with($symbol->getOriginalSymbol(), $this->config->getClassmapPrefix())) {
                        continue;
                    }

                    $symbol->setReplacement($this->config->getClassmapPrefix() . $symbol->getOriginalSymbol());
                }
            }

            // If we're a namespaced class, apply the fqdnchange.
            if ($symbol->getNamespace() !== '\\') {
                $newNamespace = $discoveredNamespaces[$symbol->getNamespace()];
                if ($newNamespace) {
                    $replacement = $this->determineNamespaceReplacement(
                        $newNamespace->getOriginalSymbol(),
                        $newNamespace->getReplacement(),
                        $symbol->getOriginalSymbol()
                    );

                    $symbol->setReplacement($replacement);

                    unset($newNamespace, $replacement);
                }
                continue;
            } else {
                // Global class.
                $replacement = $classmapPrefix . $symbol->getOriginalSymbol();
                $symbol->setReplacement($replacement);
            }
        }

        $functionsSymbols = $discoveredSymbols->getDiscoveredFunctions();

        foreach ($functionsSymbols as $symbol) {
            // Don't prefix functions in a namespace – that will be addressed by the namespace prefix.
            if ($symbol->getNamespace() !== '\\') {
                continue;
            }
            $functionPrefix = $this->config->getFunctionsPrefix();
            if (empty($functionPrefix) || str_starts_with($symbol->getOriginalSymbol(), $functionPrefix)) {
                continue;
            }

            $symbol->setReplacement($functionPrefix . $symbol->getOriginalSymbol());
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
