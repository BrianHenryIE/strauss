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
use Composer\InstalledVersions;
use Elazar\Flystream\FilesystemRegistry;
use Elazar\Flystream\StripProtocolPathNormalizer;
use League\Flysystem\WhitespacePathNormalizer;
use Monolog\Handler\PsrHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use League\Flysystem\Config;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Logger\ConsoleLogger;

abstract class AbstractRenamespacerCommand extends Command
{
    use LoggerAwareTrait;

    /** @var string */
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
            4,
            'Do not actually make any changes',
            false
        );

        $this->addOption(
            'info',
            null,
            4,
            'output level',
            false
        );

        $this->addOption(
            'debug',
            null,
            4,
            'output level',
            false
        );

        /** @var string $installedSymfonyVersion */
        $installedSymfonyVersion = InstalledVersions::getVersion('symfony/console');

        if (version_compare($installedSymfonyVersion, '7.2', '<')) {
            $this->addOption(
                'silent',
                's',
                4,
                'output level',
                false
            );
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!isset($this->config)) {
            $this->config = $this->createConfig($input);
        }

        if ($this->config->isDryRun()) {
            $normalizer = new WhitespacePathNormalizer();
            $normalizer = new StripProtocolPathNormalizer(['mem'], $normalizer);

            $this->filesystem =
                new FileSystem(
                    new ReadOnlyFileSystem(
                        $this->filesystem,
                        $normalizer
                    ),
                    $this->workingDir
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
            $this->setLogger($this->getLogger($input, $output));
        }

        $logger = new Logger('logger');
        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushProcessor(new RelativeFilepathLogProcessor($this->filesystem));
        $logger->pushProcessor(new PadColonColumnsLogProcessor());
        $logger->pushHandler(new PsrHandler($this->getLogger($input, $output)));
        $this->setLogger($logger);

        return 1;
    }

    /**
     * Symfony hook that runs before execute(). Sets working directory, filesystem and logger.
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->workingDir = getcwd() . '/';

        if (!isset($this->filesystem)) {
            $localFilesystemAdapter = new LocalFilesystemAdapter(
                '/',
                null,
                LOCK_EX,
                LocalFilesystemAdapter::SKIP_LINKS
            );

            $this->filesystem = new FileSystem(
                new \League\Flysystem\Filesystem(
                    $localFilesystemAdapter,
                    [
                        Config::OPTION_DIRECTORY_VISIBILITY => 'public',
                    ]
                ),
                $this->workingDir
            );
        }

        if (method_exists($this, 'setLogger')) {
            $this->setLogger($this->getLogger($input, $output));
        }
    }

    /**
     * Build a logger honoring optional --info/--debug/--silent flags if present.
     */
    protected function getLogger(InputInterface $input, OutputInterface $output): LoggerInterface
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
