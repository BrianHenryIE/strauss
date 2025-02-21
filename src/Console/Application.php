<?php

namespace BrianHenryIE\Strauss\Console;

use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\Console\Commands\IncludeAliasesCommand;
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

        $replaceCommand = new ReplaceCommand();
        $this->add($replaceCommand);

        $this->add(new IncludeAliasesCommand());

        $this->setDefaultCommand('dependencies');
    }
}
