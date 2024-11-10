<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Config\ChangeEnumeratorConfigInterface;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;

class ChangeEnumerator
{
    protected ChangeEnumeratorConfigInterface $config;
    protected string $workingDir;

    public function __construct(ChangeEnumeratorConfigInterface $config, string $workingDir)
    {
        $this->config = $config;
        $this->workingDir = $workingDir;
    }

    public function determineReplacements(DiscoveredSymbols $discoveredSymbols): void
    {
        foreach ($discoveredSymbols->getSymbols() as $symbol) {
            $symbolSourceFile = $symbol->getSourceFile();
            if ($symbolSourceFile instanceof FileWithDependency) {
                if (in_array(
                    $symbolSourceFile->getDependency()->getPackageName(),
                    $this->config->getExcludePackagesFromPrefixing(),
                    true
                )) {
                    continue;
                }

                foreach ($this->config->getExcludeFilePatternsFromPrefixing() as $excludeFilePattern) {
                    // TODO: This source relative path should be from the vendor dir.
                    // TODO: Should the target path be used here?
                    if (1 === preg_match($excludeFilePattern, $symbolSourceFile->getSourcePath($this->workingDir))) {
                        continue 2;
                    }
                }
            }
            
            if ($symbol instanceof NamespaceSymbol) {
                $namespaceReplacementPatterns = $this->config->getNamespaceReplacementPatterns();

                // `namespace_prefix` is just a shorthand for a replacement pattern that applies to all namespaces.

                // TODO: Maybe need to preg_quote and add regex delimiters to the patterns here.

                if (!is_null($this->config->getNamespacePrefix())) {
                    $stripPattern = '~^('.preg_quote($this->config->getNamespacePrefix(), '~') .'\\\\*)*(.*)~';
                    $strippedSymbol = preg_replace(
                        $stripPattern,
                        '$2',
                        $symbol->getOriginalSymbol()
                    );
                    $namespaceReplacementPatterns[ "~(" . preg_quote($this->config->getNamespacePrefix(), '~') . '\\\\*)*' . preg_quote($strippedSymbol, '~') . '~' ]
                        = "{$this->config->getNamespacePrefix()}\\{$strippedSymbol}";
                }

                // `namespace_replacement_patterns` should be ordered by priority.
                foreach ($namespaceReplacementPatterns as $namespaceReplacementPattern => $replacement) {
                    $prefixed = preg_replace($namespaceReplacementPattern, $replacement, $symbol->getOriginalSymbol());

                    if ($prefixed !== $symbol->getOriginalSymbol()) {
                        $symbol->setReplacement($prefixed);
                        continue 2;
                    }
                }
            }

            if ($symbol instanceof ClassSymbol) {
                // Don't double-prefix classnames.
                if (str_starts_with($symbol->getOriginalSymbol(), $this->config->getClassmapPrefix())) {
                    continue;
                }

                $symbol->setReplacement($this->config->getClassmapPrefix() . $symbol->getOriginalSymbol());
            }
        }
    }
}
