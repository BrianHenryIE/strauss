<?php
/**
 * Determine the replacements to be made to the discovered symbols.
 *
 * Typically this will just be a prefix, but more complex rules allow for replacements specific to individual symbols/namespaces.
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Config\ChangeEnumeratorConfigInterface;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use League\Flysystem\FilesystemReader;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ChangeEnumerator
{
    use LoggerAwareTrait;

    protected ChangeEnumeratorConfigInterface $config;
    protected FilesystemReader $filesystem;

    public function __construct(
        ChangeEnumeratorConfigInterface $config,
        FilesystemReader $filesystem,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->setLogger($logger ?? new NullLogger());
    }

    public function determineReplacements(DiscoveredSymbols $discoveredSymbols): void
    {
        foreach ($discoveredSymbols->getSymbols() as $symbol) {
            // TODO: this is a bit of a mess. Should be reconsidered. Previously there was 1-1 relationship between symbols and files.
            $symbolSourceFiles = $symbol->getSourceFiles();
            $symbolSourceFile = $symbolSourceFiles[array_key_first($symbolSourceFiles)];
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
                    if (1 === preg_match($excludeFilePattern, $symbolSourceFile->getSourcePath())) {
                        continue 2;
                    }
                }
            }
            
            if ($symbol instanceof NamespaceSymbol) {
                $namespaceReplacementPatterns = $this->config->getNamespaceReplacementPatterns();

                // `namespace_prefix` is just a shorthand for a replacement pattern that applies to all namespaces.

                // TODO: Maybe need to preg_quote and add regex delimiters to the patterns here.
                foreach ($namespaceReplacementPatterns as $pattern => $replacement) {
                    if (substr($pattern, 0, 1) !== substr($pattern, -1, 1)) {
                        unset($namespaceReplacementPatterns[$pattern]);
                        $pattern = '~'. preg_quote($pattern, '~') . '~';
                        $namespaceReplacementPatterns[$pattern] = $replacement;
                    }
                    unset($pattern, $replacement);
                }

                if (!is_null($this->config->getNamespacePrefix())) {
                    $stripPattern = '~^('.preg_quote($this->config->getNamespacePrefix(), '~') .'\\\\*)*(.*)~';
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

            if ($symbol instanceof ClassSymbol) {
                // Don't double-prefix classnames.
                if (str_starts_with($symbol->getOriginalSymbol(), $this->config->getClassmapPrefix())) {
                    continue;
                }

                $symbol->setReplacement($this->config->getClassmapPrefix() . $symbol->getOriginalSymbol());
            }

            if ($symbol instanceof FunctionSymbol) {
                // TODO: Add its own config option.
                $functionPrefix = strtolower($this->config->getClassmapPrefix());
                if (str_starts_with($symbol->getOriginalSymbol(), $functionPrefix)) {
                    continue;
                }

                $symbol->setReplacement($functionPrefix . $symbol->getOriginalSymbol());
            }
        }
    }
}
