<?php
/**
 *
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Console\Commands;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Composer\ProjectComposerPackage;
use BrianHenryIE\Strauss\Pipeline\Autoload\VendorComposerAutoload;
use BrianHenryIE\Strauss\Pipeline\Prefixer;
use Composer\Factory;
use Composer\Util\Platform;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PrefixComposerAutoloadFilesCommand extends AbstractRenamespacerCommand
{
    /**
     * Set name and description, add CLI arguments, call parent class to add dry-run, verbosity options.
     *
     * @used-by \Symfony\Component\Console\Command\Command::__construct
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
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @see Command::execute()
     *
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // Pipeline
            $this->loadProjectComposerPackage();
            $this->loadConfigFromComposerJson();

            parent::execute($input, $output);

            // TODO: check for `--no-dev` somewhere.

            $replacer = new Prefixer(
                $this->config,
                $this->filesystem,
                $this->logger
            );

            $replacer->prefixComposerAutoloadFiles($this->config->getAbsoluteTargetDirectory());
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }


    /**
     * 1. Load the composer.json.
     *
     * @throws Exception
     */
    protected function loadProjectComposerPackage(): void
    {
        $this->logger->notice('Loading package...');

        $this->projectComposerPackage = new ProjectComposerPackage(
            $this->filesystem->makeAbsolute(
                $this->workingDir . '/' . Factory::getComposerFile()
            )
        );
    }

    protected function loadConfigFromComposerJson(): void
    {
        $this->logger->notice('Loading composer.json config...');

        $this->config = $this->projectComposerPackage->getStraussConfig();
        $config = new StraussConfig();
        $config->setProjectAbsolutePath(Platform::getcwd());
    }
}
