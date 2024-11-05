<?php

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;

class ChangeEnumerator
{
    protected StraussConfig $config;
    protected string $workingDir;

    public function __construct(StraussConfig $config, string $workingDir)
    {
        $this->config = $config;
        $this->workingDir = $workingDir;

        $absoluteTargetDir = $workingDir . $config->getTargetDirectory();
    }

    public function determineReplacements(DiscoveredSymbols $discoveredSymbols): void
    {
        foreach ($discoveredSymbols->getSymbols() as $symbol) {
            if (in_array(
                $symbol->getFile()->getDependency()->getPackageName(),
                $this->config->getExcludePackagesFromPrefixing(),
                true
            )
            ) {
                continue;
            }

            foreach ($this->config->getExcludeFilePatternsFromPrefixing() as $excludeFilePattern) {
                if (1 === preg_match($excludeFilePattern, $symbol->getFile()->getTargetRelativePath())) {
                    continue 2;
                }
            }

            if ($symbol instanceof NamespaceSymbol) {
                // Don't double-prefix namespaces.
                if (str_starts_with($symbol->getOriginalSymbol(), $this->config->getNamespacePrefix())) {
                    continue;
                }

                $namespaceReplacementPatterns = $this->config->getNamespaceReplacementPatterns();

                // `namespace_prefix` is just a shorthand for a replacement pattern that applies to all namespaces.
                if (!isset($namespaceReplacementPatterns['~' . preg_quote($symbol->getOriginalSymbol(), '~') . '~'])) {
                    $namespaceReplacementPatterns[ '~' . preg_quote($symbol->getOriginalSymbol(), '~') . '~' ]
                        = "{$this->config->getNamespacePrefix()}\\{$symbol->getOriginalSymbol()}";
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
