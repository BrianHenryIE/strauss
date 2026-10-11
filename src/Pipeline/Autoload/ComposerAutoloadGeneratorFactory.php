<?php

/**
 * Factory, so when creating the object midway through the method, the typical instance can be
 * substituted with a mock or a stub.
 *
 * Contains no conditional logic.
 *
 * @package brianhenryie/strauss
 *
 * @see \Composer\Autoload\AutoloadGenerator
 * @see \BrianHenryIE\Strauss\Pipeline\Autoload\ComposerAutoloadGenerator
 */

namespace BrianHenryIE\Strauss\Pipeline\Autoload;

use Composer\Autoload\AutoloadGenerator;
use Composer\EventDispatcher\EventDispatcher;

/**
 * {@see ComposerAutoloadGeneratorFactory::get()} returns a new {@see ComposerAutoloadGenerator} constructed with the
 * namespace prefix as its project-unique string and the given {@see EventDispatcher}.
 */
class ComposerAutoloadGeneratorFactory
{
    /**
     * Get a AutoloadGenerator for creating/recreating the autoload files
     *
     * @param string          $namespacePrefix The configured `namespace_prefix`, hashed into `files` autoloader identifiers so they are unique to this project.
     * @param EventDispatcher $eventDispatcher Used by Composer's generator to dispatch autoload dump scripts.
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
