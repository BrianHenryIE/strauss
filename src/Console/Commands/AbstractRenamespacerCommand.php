<?php
/**
 * Log level, filesystem
 */

namespace BrianHenryIE\Strauss\Console\Commands;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Composer\ProjectComposerPackage;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Helpers\Log\PadColonColumnsLogProcessor;
use BrianHenryIE\Strauss\Helpers\Log\RelativeFilepathLogProcessor;
use BrianHenryIE\Strauss\Helpers\ReadOnlyFileSystem;
use BrianHenryIE\Strauss\Helpers\SymlinkProtectFilesystemAdapter;
use Composer\InstalledVersions;
use Elazar\Flystream\FilesystemRegistry;
use League\Flysystem\PathPrefixer;
use Monolog\Handler\PsrHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use League\Flysystem\Config;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Logger\ConsoleLogger;

abstract class AbstractRenamespacerCommand extends Command
{
    use LoggerAwareTrait;

    /** No trailing slash */
    protected string $workingDir;

    /** @var FileSystem */
    protected Filesystem $filesystem;
    protected ProjectComposerPackage $projectComposerPackage;

    protected StraussConfig $config;

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

        /** @var string $installedSymfonyVersion */
        $installedSymfonyVersion = InstalledVersions::getVersion('symfony/console');

        if (version_compare($installedSymfonyVersion, '7.2', '<')) {
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
        $workingDir       = getcwd() . '/';
        $localFsLocation = FileSystem::getFsRoot($workingDir);

        $pathNormalizer = Filesystem::makePathNormalizer($localFsLocation);

        $pathPrefixer = new PathPrefixer(
            $localFsLocation,
            DIRECTORY_SEPARATOR
        );

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
            $localFsLocation
        );

        $this->workingDir = $this->filesystem->normalizePath($workingDir);

        if (method_exists($this, 'setLogger') && !isset($this->logger)) {
            $this->setLogger($this->getConsoleLogger($input, $output));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!isset($this->config)) {
            $this->config = $this->createConfig($input);
        }

        if ($this->config->isDryRun()) {
            $this->filesystem->setAdapter(
                new ReadOnlyFileSystem(
                    $this->filesystem->getAdapter(),
                    Filesystem::makePathNormalizer($this->workingDir)
                )
            );

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

        $logger = new Logger('logger');
        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushProcessor(new RelativeFilepathLogProcessor($this->filesystem));
        $logger->pushProcessor(new PadColonColumnsLogProcessor());
        if (isset($this->logger) && !($this->logger instanceof ConsoleLogger)) {
            $logger->pushHandler(new PsrHandler($this->logger));
        }
        $logger->pushHandler(new PsrHandler($this->getConsoleLogger($input, $output)));
        $this->setLogger($logger);

        return Command::SUCCESS;
    }

    /**
     * Build a logger honoring optional --info/--debug/--silent flags if present.
     */
    protected function getConsoleLogger(InputInterface $input, OutputInterface $output): LoggerInterface
    {
        // If a subclass has a config and it is a dry-run, increase verbosity
        $isDryRun = property_exists($this, 'config') && isset($this->config) && method_exists($this->config, 'isDryRun') && $this->config->isDryRun();

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
