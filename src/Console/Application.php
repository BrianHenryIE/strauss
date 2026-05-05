<?php

namespace BrianHenryIE\Strauss\Console;

use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\Console\Commands\IncludeAutoloaderCommand;
use BrianHenryIE\Strauss\Console\Commands\PrefixComposerAutoloadFilesCommand;
use BrianHenryIE\Strauss\Console\Commands\ReplaceCommand;
use Symfony\Component\Console\Application as BaseApplication;

class Application extends BaseApplication
{
    /**
     * @param string $version
     */
    public function __construct(string $version)
    {
        parent::__construct('strauss', $version);

        $composeCommand = new DependenciesCommand();
        $this->add($composeCommand);

        $this->add(new ReplaceCommand());
        $this->add(new IncludeAutoloaderCommand());
        $this->add(new PrefixComposerAutoloadFilesCommand());

        $this->setDefaultCommand('dependencies');
    }
}
