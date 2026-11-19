<?php

/**
 * When prefixing in place, packages installed since an earlier run, e.g. by `composer update other/package`, still use
 * the names that run renamed, while the prefixed packages, not reinstalled, already declare the renamed ones.
 *
 * @package brianhenryie/strauss
 * @see     https://github.com/BrianHenryIE/strauss/issues/343
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Config\InverseReplacementsConfigInterface;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * Adds copies of the already-renamed symbols under their earlier names, renamed to their current names, for finding
 * the files which use them ({@see FileSymbolScanner::findInFilesOutsideDependencyTree()}) and for planning the changes
 * ({@see ChangePlanner}). The earlier names are worked out by {@see ChangeEnumerator}.
 *
 * With another target directory, the files are read afresh from `vendor`, so nothing uses the earlier names.
 */
class InverseReplacements
{
    use LoggerAwareTrait;

    protected InverseReplacementsConfigInterface $config;

    public function __construct(InverseReplacementsConfigInterface $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->setLogger($logger);
    }

    /**
     * @param DiscoveredSymbols $symbols The symbols, after {@see ChangeEnumerator::determineReplacements()}.
     *
     * @return DiscoveredSymbols The given symbols when there are no copies to add, e.g. not prefixing in place;
     *                           otherwise a copy of the collection with them added. The symbols themselves are shared.
     *
     * @see DiscoveredSymbols::getInverseReplacementSymbols()
     */
    public function withInverseReplacementSymbols(DiscoveredSymbols $symbols): DiscoveredSymbols
    {
        if (!$this->config->isTargetDirectoryVendor()) {
            return $symbols;
        }

        $inverseReplacementSymbols = $symbols->getInverseReplacementSymbols(
            array_keys($this->config->getPackagesToPrefix())
        );

        if (0 === count($inverseReplacementSymbols)) {
            return $symbols;
        }

        // Not rebuilt via `::toArray()`, which loses symbols of different types with the same name.
        $symbolsWithInverseReplacements = clone $symbols;

        foreach ($inverseReplacementSymbols as $inverseReplacementSymbol) {
            $symbolsWithInverseReplacements->add($inverseReplacementSymbol);
            $this->logger->debug(
                'Already renamed: {originalName} will be renamed to {replacementName}.',
                [
                    'originalName' => $inverseReplacementSymbol->getOriginalFqdnName(),
                    'replacementName' => $inverseReplacementSymbol->getReplacementFqdnName(),
                ]
            );
        }

        return $symbolsWithInverseReplacements;
    }
}
