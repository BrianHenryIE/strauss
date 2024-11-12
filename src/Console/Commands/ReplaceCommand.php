<?php
/**
 * Rename a namespace in files. (in-place renaming)
 *
 * strauss replace --from "YourCompany\\Project" --to "BrianHenryIE\\MyProject" --paths "includes,my-plugin.php"
 */

namespace BrianHenryIE\Strauss\Console\Commands;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\ReplaceConfigInterface;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Pipeline\ChangeEnumerator;
use BrianHenryIE\Strauss\Pipeline\FileEnumerator;
use BrianHenryIE\Strauss\Pipeline\FileSymbolScanner;
use BrianHenryIE\Strauss\Pipeline\Licenser;
use BrianHenryIE\Strauss\Pipeline\Prefixer;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use Exception;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

class ReplaceCommand extends Command
{
    use LoggerAwareTrait;

    /** @var string */
    protected string $workingDir;

    protected StraussConfig $config;

    /** @var Prefixer */
    protected Prefixer $replacer;

    /** @var ComposerPackage[] */
    protected array $flatDependencyTree = [];

    /**
     * ArrayAccess of \BrianHenryIE\Strauss\File objects indexed by their path relative to the output target directory.
     *
     * Each object contains the file's relative and absolute paths, the package and autoloaders it came from,
     * and flags indicating should it / has it been copied / deleted etc.
     *
     */
    protected DiscoveredFiles $discoveredFiles;
    protected DiscoveredSymbols $discoveredSymbols;

    /**
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
        $this->setLogger(
            new ConsoleLogger(
                $output,
                [ LogLevel::INFO => OutputInterface::VERBOSITY_NORMAL ]
            )
        );

        $workingDir       = getcwd() . DIRECTORY_SEPARATOR;
        $this->workingDir = $workingDir;

        try {
            $config = $this->createConfig($input);

            // Pipeline

            $this->enumerateFiles($config);

            $this->determineChanges($config);

            $this->performReplacements($config);

            $this->performReplacementsInProjectFiles($config);

            $this->addLicenses($config);
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());

            return 1;
        }

        return Command::SUCCESS;
    }

    protected function createConfig(InputInterface $input): ReplaceConfigInterface
    {
        $config = new StraussConfig();

        $from = $input->getOption('from');
        $to = $input->getOption('to');

        // TODO:
        $config->setNamespaceReplacementPatterns([$from => $to]);

        $paths = explode(',', $input->getOption('paths'));

        $config->setUpdateCallSites($paths);

        return $config;
    }


    protected function enumerateFiles(ReplaceConfigInterface $config): void
    {
        $this->logger->info('Enumerating files...');
        $this->discoveredFiles = (new FileEnumerator($this->workingDir, $config))->compileFileListForPaths($config->getUpdateCallSites());
    }

    // 4. Determine namespace and classname changes
    protected function determineChanges(ReplaceConfigInterface $config): void
    {
        $this->logger->info('Determining changes...');

        $fileScanner = new FileSymbolScanner($config);

        $this->discoveredSymbols = $fileScanner->findInFiles($this->discoveredFiles);

        $changeEnumerator = new ChangeEnumerator(
            $config,
            $this->workingDir
        );
        $changeEnumerator->determineReplacements($this->discoveredSymbols);
    }

    // 5. Update namespaces and class names.
    // Replace references to updated namespaces and classnames throughout the dependencies.
    protected function performReplacements(ReplaceConfigInterface $config): void
    {
        $this->logger->info('Performing replacements...');

        $this->replacer = new Prefixer($config, $this->workingDir);

        $this->replacer->replaceInFiles($this->discoveredSymbols, $this->discoveredFiles->getFiles());
    }

    protected function performReplacementsInProjectFiles(ReplaceConfigInterface $config): void
    {

        $callSitePaths = $config->getUpdateCallSites();

        if (empty($callSitePaths)) {
            return;
        }

        $projectReplace = new Prefixer($config, $this->workingDir);

        $fileEnumerator = new FileEnumerator(
            $this->workingDir,
            $config
        );

        $phpFiles = $fileEnumerator->compileFileListForPaths($callSitePaths);

        $phpFilesRelativePaths = array_map(
            fn($file) => $file->getSourcePath($this->workingDir),
            $phpFiles->getFiles()
        );

        // TODO: Warn when a file that was specified is not found
        // $this->logger->warning('Expected file not found from project autoload: ' . $absolutePath);

        $projectReplace->replaceInProjectFiles($this->discoveredSymbols, $phpFilesRelativePaths);
    }


    protected function addLicenses(ReplaceConfigInterface $config): void
    {
        $this->logger->info('Adding licenses...');

        $username = trim(shell_exec('git config user.name'));
        $email = trim(shell_exec('git config user.email'));

        if (!empty($username) && !empty($email)) {
            // e.g. "Brian Henry <BrianHenryIE@gmail.com>".
            $author = $username . ' <' . $email . '>';
        } else {
            // e.g. "brianhenry".
            $author = get_current_user();
        }

        $dependencies = $this->flatDependencyTree;

        $licenser = new Licenser($config, $this->workingDir, $dependencies, $author);

        $licenser->copyLicenses();

        $modifiedFiles = $this->replacer->getModifiedFiles();
        $licenser->addInformationToUpdatedFiles($modifiedFiles);
    }
}