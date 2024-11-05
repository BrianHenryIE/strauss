<?php

namespace BrianHenryIE\Strauss\Files;

use BrianHenryIE\Strauss\Composer\ComposerPackage;

class FileWithDependency extends File implements HasDependency
{

    /**
     * @var string The path to the file relative to the package root.
     */
    protected string $packageRelativePath;

    /**
     * The project dependency that this file belongs to.
     */
    protected ComposerPackage $dependency;

    /**
     * @var string[] The autoloader types that this file is included in.
     */
    protected array $autoloaderTypes = [];

    public function __construct(ComposerPackage $dependency, string $packageRelativePath, string $sourceAbsolutePath)
    {
        parent::__construct($sourceAbsolutePath);

        $this->packageRelativePath = $packageRelativePath;
        $this->dependency          = $dependency;
    }

    public function getDependency(): ComposerPackage
    {
        return $this->dependency;
    }

    public function getTargetRelativePath(): string
    {
        return $this->dependency->getRelativePath() . $this->packageRelativePath;
    }


    /**
     * Record the autoloader it is found in. Which could be all of them.
     */
    public function addAutoloader(string $autoloaderType): void
    {
        $this->autoloaderTypes = array_unique(array_merge($this->autoloaderTypes, array($autoloaderType)));
    }

    public function isFilesAutoloaderFile(): bool
    {
        return in_array('files', $this->autoloaderTypes, true);
    }
}
