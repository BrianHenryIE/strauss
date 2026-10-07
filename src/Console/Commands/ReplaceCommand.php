<?php
/**
 * Rename a namespace in files. (in-place renaming)
 *
 * strauss replace --from "YourCompany\\Project" --to "BrianHenryIE\\MyProject" --paths "includes,my-plugin.php"
 */

namespace BrianHenryIE\Strauss\Console\Commands;

use BrianHenryIE\Strauss\Composer\Extra\ReplaceConfigInterface;
use BrianHenryIE\Strauss\Pipeline\FileEnumerator;
use BrianHenryIE\Strauss\Pipeline\Parallel\WorkerPool;
use BrianHenryIE\Strauss\Pipeline\Prefixer;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ReplaceCommand extends AbstractRenamespacerCommand
{
    protected function getConfig(): ReplaceConfigInterface
    {
        return $this->config;
    }

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
        $this->setName('replace');
        $this->setDescription("Rename a namespace in files.");
        $this->setHelp('');

        $this->addOption(
            'from',
            null,
            InputArgument::OPTIONAL,
            'Original namespace'
        );

        $this->addOption(
            'to',
            null,
            InputArgument::OPTIONAL,
            'New namespace'
        );

        $this->addOption(
            'paths',
            null,
            InputArgument::OPTIONAL,
            'Comma separated list of files and directories to update. Default is the current working directory.',
            getcwd()
        );

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
            /**
             * @see \Symfony\Component\Console\Command\Command
             * @see AbstractRenamespacerCommand
             */
            parent::execute($input, $output);

            $this->updateConfigFromCli($input);
            $this->discoveredSymbols = new DiscoveredSymbols();

            $this->runPipeline();
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());

            return 1;
        }

        return Command::SUCCESS;
    }


    /**
     * @return string[]
     */
    protected function getPipelineSteps(): array
    {
        return [
            'enumerateFiles',
            'scanFilesForSymbols',
            'enumerateAutoloadedFiles',
            'markSymbolsForRenaming',
            'determineChanges',
            'planChanges',
            'performReplacements',
            'performReplacementsInProjectFiles',
            'addLicenses',
        ];
    }

    protected function updateConfigFromCli(InputInterface $input): void
    {
        $this->logger->notice('Loading cli config...');

        /** @var string $inputFrom */
        $inputFrom = $input->getOption('from');

        /** @var string $inputTo */
        $inputTo = $input->getOption('to');

        // TODO: validate input exists.

        // TODO:
        $this->config->setNamespaceReplacementPatterns([$inputFrom => $inputTo]);

        /** @var string $inputPaths */
        $inputPaths = $input->getOption('paths');
        $paths = explode(',', $inputPaths);

        $this->config->setUpdateCallSites($paths);
    }


    protected function enumerateFiles(): void
    {
        $config = $this->getConfig();

        $this->logger->info('Enumerating files...');
        $relativeUpdateCallSites = $config->getUpdateCallSites() ?? [];
        $updateCallSites = array_map(
            fn($path) => false !== strpos($path, $this->workingDir) ? $path : $this->workingDir . '/'.$path,
            $relativeUpdateCallSites
        );
        $fileEnumerator = new FileEnumerator($config, $this->filesystem, $this->logger);
        $this->discoveredFiles = $fileEnumerator->compileFileListForPaths($updateCallSites);
    }

    /**
     * The files are analysed in this process.
     */
    protected function getWorkerPool(): ?WorkerPool
    {
        return null;
    }

    protected function performReplacementsInProjectFiles(): void
    {
        $config = $this->getConfig();

        $relativeCallSitePaths = $this->config->getUpdateCallSites();

        if (empty($relativeCallSitePaths)) {
            return;
        }

        $callSitePaths = array_map(
            fn($path) => false !== strpos($path, $this->workingDir) ? $path : $this->workingDir . '/'. $path,
            $relativeCallSitePaths
        );

        $projectReplace = new Prefixer($config, $this->filesystem, $this->logger);

        $fileEnumerator = new FileEnumerator(
            $config,
            $this->filesystem,
            $this->logger
        );

        $files = $fileEnumerator->compileFileListForPaths($callSitePaths);

        // TODO: Warn when a file that was specified is not found (during config validation).
        // $this->logger->warning('Expected file not found from project autoload: ' . $absolutePath);

        $projectReplace->replaceInProjectFiles($this->discoveredSymbols, $files);
    }

    /**
     * There may be no `composer.json`: use the Git user, or the current system user.
     */
    protected function getLicenseAuthor(): string
    {
        $username = trim(shell_exec('git config user.name') ?: '');
        $email = trim(shell_exec('git config user.email') ?: '');

        if (!empty($username) && !empty($email)) {
            // e.g. "Brian Henry <BrianHenryIE@gmail.com>".
            return $username . ' <' . $email . '>';
        }

        // e.g. "brianhenry".
        return get_current_user();
    }
}
