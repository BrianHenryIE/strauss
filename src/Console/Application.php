<?php

/**
 * The Symfony Console application behind the `strauss` executable, registering each Strauss command.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Console;

use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\Console\Commands\DumpAutoloadCommand;
use BrianHenryIE\Strauss\Console\Commands\IncludeAutoloaderCommand;
use BrianHenryIE\Strauss\Console\Commands\PrefixComposerAutoloadFilesCommand;
use BrianHenryIE\Strauss\Console\Commands\ReplaceCommand;
use BrianHenryIE\Strauss\Console\Commands\WorkerCommand;
use Symfony\Component\Console\Application as BaseApplication;

/**
 * Extends Symfony's {@see BaseApplication}, adding {@see DependenciesCommand}, {@see ReplaceCommand},
 * {@see DumpAutoloadCommand}, {@see IncludeAutoloaderCommand}, {@see PrefixComposerAutoloadFilesCommand} and the hidden
 * {@see WorkerCommand}, and makes `dependencies` the default so running `strauss` with no arguments copies and prefixes.
 */
class Application extends BaseApplication
{
    /**
     * @param string $version Strauss version string shown by `--version`.
     */
    public function __construct(string $version)
    {
        parent::__construct('strauss', $version);

        $composeCommand = new DependenciesCommand();
        $this->add($composeCommand);

        $this->add(new ReplaceCommand());
        $this->add(new DumpAutoloadCommand());
        $this->add(new IncludeAutoloaderCommand());
        $this->add(new PrefixComposerAutoloadFilesCommand());
        $this->add(new WorkerCommand());

        $this->setDefaultCommand('dependencies');
    }
}
