<?php
/**
 * The contract for a file that belongs to a Composer package dependency, as opposed to a project or
 * `vendor/composer` file.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Files;

use BrianHenryIE\Strauss\Composer\ComposerPackage;

/**
 * Implemented by {@see FileWithDependency}. Exposes the {@see ComposerPackage} the file came from and records which
 * autoload keys (`files`, `classmap`, `psr-0`, `psr-4`) reference the file, so files loaded by the `files`
 * autoloader can be identified.
 */
interface HasDependency
{

    public function getDependency(): ComposerPackage;

    /**
     * Record the autoloader it is found in. Which could be all of them.
     *
     * @param string $autoloaderType The autoload key the file was found under, e.g. `psr-4`, `psr-0`, `classmap` or `files`.
     */
    public function addAutoloader(string $autoloaderType): void;

    public function isFilesAutoloaderFile(): bool;
}
