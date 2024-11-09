<?php

namespace BrianHenryIE\Strauss\Files;

use BrianHenryIE\Strauss\Composer\ComposerPackage;

interface HasDependency
{

    public function getDependency(): ComposerPackage;

    /**
     * Record the autoloader it is found in. Which could be all of them.
     */
    public function addAutoloader(string $autoloaderType): void;

    public function isFilesAutoloaderFile(): bool;
}
