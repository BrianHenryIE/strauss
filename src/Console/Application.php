<?php

namespace BrianHenryIE\Strauss\Console;

use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\Console\Commands\Replace;
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

        $this->setDefaultCommand('dependencies');
    }
}
