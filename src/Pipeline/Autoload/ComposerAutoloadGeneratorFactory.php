<?php

/**
 * Factory, so when creating the object midway through the method, the typical instance can be
 * substituted with a mock or a stub.
 *
 * Contains no conditional logic.
 *
 * @see \Composer\Autoload\AutoloadGenerator
 * @see \BrianHenryIE\Strauss\Pipeline\Autoload\ComposerAutoloadGenerator
 */

namespace BrianHenryIE\Strauss\Pipeline\Autoload;

use Composer\Autoload\AutoloadGenerator;
use Composer\EventDispatcher\EventDispatcher;

class ComposerAutoloadGeneratorFactory
{
    /**
     * Get a AutoloadGenerator for creating/recreating the autoload files
     *
     * @param string $namespacePrefix
     * @param EventDispatcher $eventDispatcher
     */
    public function get(
        string $namespacePrefix,
        EventDispatcher $eventDispatcher
    ): AutoloadGenerator {
        return new ComposerAutoloadGenerator(
            $namespacePrefix,
            $eventDispatcher
        );
    }
}
