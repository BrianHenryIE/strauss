<?php

/**
 * Prefixes the classes in Composer's own runtime files in the target directory, e.g. `autoload_real.php`,
 * `ClassLoader.php` and `InstalledVersions.php`, so they do not conflict with another plugin's Composer autoloader.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Console\Commands;

use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Registered as `prefix-vendor-autoload`. Loads the project's config from `composer.json` and calls
 * {@see AbstractRenamespacerCommand::prefixComposerAutoloadFiles()}, which uses {@see \BrianHenryIE\Strauss\Pipeline\Prefixer}
 * on the target directory's `composer` directory.
 */
class PrefixComposerAutoloadFilesCommand extends AbstractRenamespacerCommand
{
    /**
     * Set name and description, add CLI arguments, call parent class to add dry-run, verbosity options.
     *
     * @used-by  \Symfony\Component\Console\Command\Command::__construct
     * @override {@see \Symfony\Component\Console\Command\Command::configure()} empty method.
     *
     * @return void
     */
    protected function configure()
    {
        $this->setName('prefix-vendor-autoload');
        $this->setDescription("Prefixes Composer's autoload_real.php etc.");

        parent::configure();
    }

    /**
     * @param InputInterface  $input  Command-line arguments and options.
     * @param OutputInterface $output Console output for logging.
     *
     * @see Command::execute()
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // Pipeline
            $this->loadProjectComposerPackage();
            $this->loadConfigFromComposerJson();

            parent::execute($input, $output);

            // TODO: check for `--no-dev` somewhere.

            $this->prefixComposerAutoloadFiles();
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
