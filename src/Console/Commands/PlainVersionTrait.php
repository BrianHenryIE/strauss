<?php
/**
 * `strauss --plainVersion` => `0.1.2`.
 * This trait is added to the {@see DependenciesCommand} because it is the default command.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/92
 *
 * @see \BrianHenryIE\Strauss\Console\Commands\DependenciesCommand
 */

namespace BrianHenryIE\Strauss\Console\Commands;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

trait PlainVersionTrait
{
    protected function configure()
    {
        $this->addArgument(
            'plainVersion',
            InputArgument::OPTIONAL,
            'Original namespace'
        );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @see Command::execute()
     *
     * @see Application::getLongVersion()
     *
     * @return int|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (true === $input->hasArgument('plainVersion')) {
            /**
             * @see Application::getVersion()
             */
            $output->writeln($this->getApplication()->getVersion());

            return Command::SUCCESS;
        }
    }
}
