<?php
/**
 * Log level, filesystem
 */

namespace BrianHenryIE\Strauss\Console\Commands;

use BrianHenryIE\Strauss\Composer\DependenciesCollection;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Composer\ProjectComposerPackage;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\Helpers\Flysystem\ReadOnlyFileSystemAdapter;
use BrianHenryIE\Strauss\Helpers\Flysystem\SymlinkProtectFilesystemAdapter;
use BrianHenryIE\Strauss\Helpers\Log\PadColonColumnsLogProcessor;
use BrianHenryIE\Strauss\Helpers\Log\RelativeFilepathLogProcessor;
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
     * @var LoggerInterface&Logger
     */
    protected $logger;

    /** No trailing slash */
    protected string $workingDir;

    /** @var FileSystem */
    protected Filesystem $filesystem;
    protected ProjectComposerPackage $projectComposerPackage;

    protected StraussConfig $config;

    protected DependenciesCollection $flatDependencyTree;

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
         * @var string $installedSymfonyVersion
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
        $this->flatDependencyTree = new DependenciesCollection([]);

        $logger = new Logger('logger');
        $this->logger = $logger;

        $workingDir      = Platform::getcwd();
        $localFsLocation = FileSystem::getFsRoot($workingDir);

        $pathNormalizer = Filesystem::makePathNormalizer($localFsLocation);

        $pathPrefixer = new PathPrefixer(
            $localFsLocation,
            DIRECTORY_SEPARATOR
        );

        /**
         * `league/flysystem` v2.x throws deprecation errors on newer PHP versions.
         * `league/flysystem` v3.x requires PHP ^8.02 and Strauss's backward compatibility promise keeps us at 7.4 until WordPress itself requires newer PHP.
         */
        set_error_handler(function (int $errNo, string $errstr, string $errFile, int $errLine): bool {
            return true;
        }, E_DEPRECATED | E_USER_DEPRECATED);

        try {
        // Extends `LocalFilesystemAdapter`.
            $localFilesystemAdapter = new SymlinkProtectFilesystemAdapter(
                $localFsLocation,
                $pathNormalizer,
                $pathPrefixer,
                $logger
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
                    Filesystem::makePathNormalizer($this->workingDir)
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

//            $this->logger->reset();
//            $this->configureLogger($this->logger, $input, $output);
        $this->setLogger(
            $this->getMonologLogger($input, $output)
        );

        return Command::SUCCESS;
    }

    protected function getMonologLogger(InputInterface $input, OutputInterface $output): Logger
    {
        $logger = new Logger('logger');
        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushProcessor(RelativeFilepathLogProcessor::make($this->filesystem));
        $logger->pushProcessor(PadColonColumnsLogProcessor::make());
        $logger->pushHandler(new PsrHandler($this->getPsrLogger($input, $output)));
        return $logger;
    }

    /**
     * Build a logger honoring optional --info/--debug/--silent flags if present.
     */
    protected function getPsrLogger(InputInterface $input, OutputInterface $output): LoggerInterface
//    protected function getConsoleLogger(InputInterface $input, OutputInterface $output): LoggerInterface
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
}
