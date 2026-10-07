<?php
/**
 * Log level, filesystem
 */

namespace BrianHenryIE\Strauss\Console\Commands;

use BrianHenryIE\FlysystemReadOnly\ReadOnlyFileSystemAdapter;
use BrianHenryIE\Strauss\Composer\DependenciesCollection;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Composer\ProjectComposerPackage;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\Helpers\Flysystem\SymlinkProtectFilesystemAdapter;
use BrianHenryIE\Strauss\Helpers\Log\PadColonColumnsLogProcessor;
use BrianHenryIE\Strauss\Helpers\Log\RelativeFilepathLogProcessor;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Pipeline\AutoloadedEnumerator;
use BrianHenryIE\Strauss\Pipeline\ChangeEnumerator;
use BrianHenryIE\Strauss\Pipeline\ChangePlanner;
use BrianHenryIE\Strauss\Pipeline\FileSymbolScanner;
use BrianHenryIE\Strauss\Pipeline\Licenser;
use BrianHenryIE\Strauss\Pipeline\MarkSymbolsForRenaming;
use BrianHenryIE\Strauss\Pipeline\Parallel\ParallelConfig;
use BrianHenryIE\Strauss\Pipeline\Parallel\WorkerPool;
use BrianHenryIE\Strauss\Pipeline\Prefixer;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use Composer\Factory;
use Exception;
use Composer\Util\Platform;
use Elazar\Flystream\FilesystemRegistry;
use League\Flysystem\Config;
use League\Flysystem\PathPrefixer;
use Monolog\Handler\PsrHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractRenamespacerCommand extends Command
{
    /**
     * @var Logger&LoggerInterface
     */
    protected $logger;

    /** No trailing slash */
    protected string $workingDir;

    protected FileSystem $filesystem;

    protected ProjectComposerPackage $projectComposerPackage;

    protected StraussConfig $config;

    protected DependenciesCollection $flatDependencyTree;

    /** @var Prefixer */
    protected Prefixer $replacer;

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
     * Set name and description, call parent class to add dry-run, verbosity options.
     *
     * @used-by \Symfony\Component\Console\Command\Command::__construct
     * @override {@see \Symfony\Component\Console\Command\Command::configure()} empty method.
     *
     * @return void
     */
    protected function configure()
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_OPTIONAL,
            'Do not actually make any changes',
            false
        );

        $this->addOption(
            'info',
            null,
            InputOption::VALUE_OPTIONAL,
            'output level',
            false
        );

        $this->addOption(
            'debug',
            null,
            InputOption::VALUE_OPTIONAL,
            'output level',
            false
        );

        // symfony/console 7.2 added a global `--silent` option to every command. Only register our own
        // `--silent`/`-s` on older versions, otherwise the definitions collide with
        // "An option named 'silent' already exists." when the application definition is merged.
        /**
         * When run via. `strauss.phar`, classes such as `InstalledVersions` are prefixed, but when installed
         * via Composer, the unprefixed version is used.
         *
         * @var ?string $installedSymfonyVersion
         */
        $installedSymfonyVersion = class_exists(\BrianHenryIE\Strauss\Composer\InstalledVersions::class)
            ? \BrianHenryIE\Strauss\Composer\InstalledVersions::getVersion('symfony/console')
            : \Composer\InstalledVersions::getVersion('symfony/console');

        if ($installedSymfonyVersion === null || version_compare($installedSymfonyVersion, '7.2', '<')) {
            $this->addOption(
                'silent',
                's',
                InputOption::VALUE_OPTIONAL,
                'output level',
                false
            );
        }
    }

    /**
     * Symfony hook that runs before execute(). Sets working directory, filesystem and logger.
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        // Instantiate the monolog logger early, reconfigure it later.
        $logger = new Logger('logger');
        $this->logger = $logger;

        $this->flatDependencyTree = new DependenciesCollection([]);

        /**
         * `league/flysystem` v2.x throws deprecation errors on newer PHP versions.
         * `league/flysystem` v3.x requires PHP ^8.02 and Strauss's backward compatibility promise keeps us at 7.4 until WordPress itself requires newer PHP.
         */
        set_error_handler(function (int $errNo, string $errstr, string $errFile, int $errLine): bool {
            return true;
        }, E_DEPRECATED | E_USER_DEPRECATED);

        $workingDir      = Platform::getcwd();
        $localFsLocation = FileSystem::getFsRoot($workingDir);

        $pathNormalizer = FileSystem::makePathNormalizer($localFsLocation);

        $pathPrefixer = new PathPrefixer(
            $localFsLocation,
            DIRECTORY_SEPARATOR
        );

        try {
        // Extends `LocalFilesystemAdapter`.
            $localFilesystemAdapter = new SymlinkProtectFilesystemAdapter(
                $localFsLocation,
                $pathNormalizer,
                $pathPrefixer,
                $this->logger
            );

            $this->filesystem = new FileSystem(
                $localFilesystemAdapter,
                [
                    Config::OPTION_DIRECTORY_VISIBILITY => 'public',
                ],
                $pathNormalizer,
                $pathPrefixer,
                $localFsLocation,
                $workingDir,
            );
        } finally {
            restore_error_handler();
        }

        $this->workingDir = $this->filesystem->normalizePath($workingDir);
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger->pushHandler(new PsrHandler($logger));
    }

    public function configureLogger(LoggerInterface $logger): void
    {
        $this->logger->pushHandler(new PsrHandler($logger));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!isset($this->config)) {
            $this->config = $this->createConfig($input);
        }

        if ($this->config->isDryRun()) {
            /**
             * `league/flysystem` v2.x throws deprecation errors on newer PHP versions.
             * `league/flysystem` v3.x requires PHP ^8.02 and Strauss's backward compatibility promise keeps us at 7.4 until WordPress itself requires newer PHP.
             */
            set_error_handler(function (int $errNo, string $errstr, string $errFile, int $errLine): bool {
                return true;
            }, E_DEPRECATED | E_USER_DEPRECATED);

            $this->filesystem->setAdapter(
                new ReadOnlyFileSystemAdapter(
                    $this->filesystem->getAdapter(),
                    FileSystem::makePathNormalizer($this->workingDir)
                )
            );
            $this->filesystem->setLocalFsLocation('mem://');

            restore_error_handler();

            /** @var FilesystemRegistry $registry */
            $registry = \Elazar\Flystream\ServiceLocator::get(\Elazar\Flystream\FilesystemRegistry::class);

            // Register a file stream mem:// to handle file operations by third party libraries.
            // This exception handling probably doesn't matter in real life but does in unit tests.
            try {
                $registry->get('mem');
            } catch (\Exception $e) {
                $registry->register('mem', $this->filesystem);
            }
        }

        $this->logger = $this->getMonologLogger($input, $output);

        return Command::SUCCESS;
    }

    protected function getMonologLogger(InputInterface $input, OutputInterface $output): Logger
    {
        $logger = $this->logger instanceof Logger
            ? $this->logger
            : new Logger('logger');

        $this->configureMonologLogger($logger, $input, $output);

        return $logger;
    }

    protected function configureMonologLogger(Logger $logger, InputInterface $input, OutputInterface $output): void
    {
        $logger->reset();
        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushProcessor(RelativeFilepathLogProcessor::make($this->filesystem));
        $logger->pushProcessor(PadColonColumnsLogProcessor::make());
        $logger->pushHandler(new PsrHandler($this->getPsrLogger($input, $output)));
    }

    /**
     * Build a logger honoring optional --info/--debug/--silent flags if present.
     *
     * TODO: maybe this should be called ~`::getConsoleLogger()`.
     */
    protected function getPsrLogger(InputInterface $input, OutputInterface $output): LoggerInterface
    {
        // If a subclass has a config and it is a dry-run, increase verbosity
        $isDryRun = isset($this->config) && $this->config->isDryRun();

        // Who would want to dry-run without output?
        if (!$isDryRun && $input->hasOption('silent') && $input->getOption('silent') !== false) {
            return new NullLogger();
        }

        $logLevel = [LogLevel::NOTICE => OutputInterface::VERBOSITY_NORMAL];

        if ($input->hasOption('info') && $input->getOption('info') !== false) {
            $logLevel[LogLevel::INFO] = OutputInterface::VERBOSITY_NORMAL;
        }

        if ($isDryRun || ($input->hasOption('debug') && $input->getOption('debug') !== false)) {
            $logLevel[LogLevel::INFO] = OutputInterface::VERBOSITY_NORMAL;
            $logLevel[LogLevel::DEBUG] = OutputInterface::VERBOSITY_NORMAL;
        }

        return new ConsoleLogger($output, $logLevel);
    }

    protected function createConfig(InputInterface $input): StraussConfig
    {
        return new StraussConfig();
    }

    /**
     * The names of the methods to run, in order.
     *
     * @return string[]
     */
    protected function getPipelineSteps(): array
    {
        return [];
    }

    /**
     * Run each of {@see self::getPipelineSteps()}.
     */
    protected function runPipeline(): void
    {
        foreach ($this->getPipelineSteps() as $step) {
            $this->timed($step);
        }
    }

    /**
     * Determine every edit to make to every file, from the symbols marked for renaming and each file's code map,
     * before any file is copied or modified.
     */
    protected function planChanges(): void
    {
        $this->logger->notice('Planning changes to files...');

        $changePlanner = new ChangePlanner($this->logger);
        $changePlanner->planInFiles($this->discoveredSymbols, $this->discoveredFiles->getFiles());
    }

    /**
     * The author to credit in the license header of modified files: the project's `composer.json` author.
     */
    protected function getLicenseAuthor(): string
    {
        return $this->projectComposerPackage->getAuthor();
    }

    /**
     * Run one pipeline step and log how long it took, for finding where the time goes on large projects.
     */
    protected function timed(string $step): void
    {
        $startedAt = microtime(true);

        $this->{$step}();

        $this->logger->debug('{step} took {seconds}s', [
            'step' => $step,
            'seconds' => number_format(microtime(true) - $startedAt, 3),
        ]);
    }

    /**
     * Load the project's composer package using the current working directory.
     *
     * @throws Exception
     */
    protected function loadProjectComposerPackage(): void
    {
        $this->logger->notice('Loading package...');

        $composerFilePath = $this->filesystem->makeAbsolute(
            $this->filesystem->normalizePath(
                $this->workingDir . '/' .Factory::getComposerFile()
            )
        );
        $defaultComposerFilePath = $this->filesystem->makeAbsolute($this->workingDir . '/composer.json');
        if ($composerFilePath !== $defaultComposerFilePath) {
            $this->logger->info('Using: ' . $composerFilePath);
        }

        $composerFilePath = $this->filesystem->normalizePath($composerFilePath);
        $this->projectComposerPackage = new ProjectComposerPackage(
            $this->filesystem->makeAbsolute($composerFilePath)
        );

        // TODO: Print the config that Strauss is using.
        // Maybe even highlight what is default config and what is custom config.
    }

    /**
     * Load Strauss config from the project's composer.json.
     */
    protected function loadConfigFromComposerJson(): void
    {
        $this->logger->notice('Loading composer.json config...');

        $this->config = $this->projectComposerPackage->getStraussConfig();
    }

    protected function updateConfigFromCli(InputInterface $input): void
    {
        $this->logger->notice('Loading cli config...');

        $this->config->updateFromCli($input);
    }

    protected function scanFilesForSymbols(): void
    {
        $this->logger->notice('Scanning files for defined symbols...');

        $fileSymbolScanner = new FileSymbolScanner(
            $this->config,
            $this->discoveredSymbols,
            $this->filesystem,
            $this->logger
        );
        $fileSymbolScanner->setWorkerPool($this->getWorkerPool());

        $fileSymbolScanner->findInFiles($this->discoveredFiles);
    }

    /**
     * Worker processes to parse files in, or null to parse them in this process: when configured off, when there
     * is only one CPU, or during `--dry-run` (the workers would not see the in-memory filesystem).
     */
    protected function getWorkerPool(): ?WorkerPool
    {
        if ($this->config->isDryRun() || !function_exists('proc_open')) {
            return null;
        }

        $parallelConfig = ParallelConfig::detect($this->config->getParallel());

        if ($parallelConfig->isSequential()) {
            return null;
        }

        return new WorkerPool($parallelConfig, null, $this->logger);
    }

    protected function enumerateAutoloadedFiles(): void
    {
        $this->logger->notice('Enumerating autoloaded files and symbols...');

        $autoloadFilesEnumerator = new AutoloadedEnumerator(
            $this->config,
            $this->filesystem,
            $this->logger
        );
        $autoloadFilesEnumerator->scanSetIsAutoloaded($this->discoveredFiles, $this->discoveredSymbols);
    }

    protected function markSymbolsForRenaming(): void
    {
        $this->logger->notice('Marking symbols to rename...');

        $markSymbolsForRenaming = new MarkSymbolsForRenaming(
            $this->config,
            $this->filesystem,
            $this->logger
        );

        $markSymbolsForRenaming->scanSetDoRename($this->discoveredSymbols);
    }

    protected function determineChanges(): void
    {
        $this->logger->notice('Determining changes...');

        $changeEnumerator = new ChangeEnumerator(
            $this->config,
            $this->logger
        );
        $changeEnumerator->determineReplacements($this->discoveredSymbols);
    }

    // 5. Update namespaces and class names.
    // Replace references to updated namespaces and classnames throughout the dependencies.
    protected function performReplacements(): void
    {
        $this->logger->notice('Performing replacements...');

        $this->replacer = new Prefixer(
            $this->config,
            $this->filesystem,
            $this->logger
        );

        $this->replacer->replaceInFiles(
            $this->discoveredSymbols,
            $this->discoveredFiles->getFiles()
        );
    }

    protected function addLicenses(): void
    {
        $this->logger->notice('Adding licenses...');

        $author = $this->getLicenseAuthor();

        $dependencies = $this->flatDependencyTree;

        $licenser = new Licenser(
            $this->config,
            $dependencies,
            $author,
            $this->filesystem,
            $this->logger
        );

        $licenser->copyLicenses();

        $modifiedFiles = $this->replacer->getModifiedFiles();
        $licenser->addInformationToUpdatedFiles($modifiedFiles);
    }

    protected function prefixComposerAutoloadFiles() : void
    {
        $replacer = $this->replacer ?? new Prefixer($this->config, $this->filesystem, $this->logger);

        $replacer->prefixComposerAutoloadFiles($this->config->getAbsoluteTargetDirectory());
    }
}
