<?php

namespace BrianHenryIE\Strauss\Console\Commands;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Composer\ProjectComposerPackage;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Helpers\Log\RelativeFilepathLogProcessor;
use BrianHenryIE\Strauss\Helpers\ReadOnlyFileSystem;
use BrianHenryIE\Strauss\Helpers\SymlinkProtectFilesystemAdapter;
use BrianHenryIE\Strauss\Pipeline\Aliases\Aliases;
use BrianHenryIE\Strauss\Pipeline\Autoload;
use BrianHenryIE\Strauss\Pipeline\Autoload\VendorComposerAutoload;
use BrianHenryIE\Strauss\Pipeline\AutoloadedFilesEnumerator;
use BrianHenryIE\Strauss\Pipeline\ChangeEnumerator;
use BrianHenryIE\Strauss\Pipeline\Cleanup\Cleanup;
use BrianHenryIE\Strauss\Pipeline\Cleanup\InstalledJson;
use BrianHenryIE\Strauss\Pipeline\Copier;
use BrianHenryIE\Strauss\Pipeline\DependenciesEnumerator;
use BrianHenryIE\Strauss\Pipeline\FileCopyScanner;
use BrianHenryIE\Strauss\Pipeline\FileEnumerator;
use BrianHenryIE\Strauss\Pipeline\FileSymbolScanner;
use BrianHenryIE\Strauss\Pipeline\Licenser;
use BrianHenryIE\Strauss\Pipeline\Prefixer;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Composer\Factory;
use Composer\InstalledVersions;
use Elazar\Flystream\FilesystemRegistry;
use Elazar\Flystream\StripProtocolPathNormalizer;
use Exception;
use League\Flysystem\Config;
use League\Flysystem\Local\LocalFilesystemAdapter;
use BrianHenryIE\Strauss\Helpers\PathPrefixer;
use League\Flysystem\WhitespacePathNormalizer;
use Monolog\Handler\PsrHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

class DependenciesCommand extends Command
{
    use LoggerAwareTrait;

    /** @var string */
    protected string $workingDir;

    protected StraussConfig $config;

    protected ProjectComposerPackage $projectComposerPackage;

    /** @var Prefixer */
    protected Prefixer $replacer;

    protected DependenciesEnumerator $dependenciesEnumerator;

    /** @var array<string,ComposerPackage> */
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

    protected Filesystem $filesystem;

    /**
     * @return void
     */
    protected function configure()
    {
        $this->setName('dependencies');
        $this->setDescription("Copy composer's `require` and prefix their namespace and classnames.");
        $this->setHelp('');

        $this->addOption(
            'updateCallSites',
            null,
            InputArgument::OPTIONAL,
            'Should replacements also be performed in project files? true|list,of,paths|false'
        );

        $this->addOption(
            'deleteVendorPackages',
            null,
            4,
            'Should original packages be deleted after copying? true|false',
            false
        );
        // Is there a nicer way to add aliases?
        $this->addOption(
            'delete_vendor_packages',
            null,
            4,
            '',
            false
        );

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

        if (version_compare(InstalledVersions::getVersion('symfony/console'), '7.2', '<')) {
            $this->addOption(
                'silent',
                's',
                4,
                'output level',
                false
            );
        }

        $localFilesystemLocation = PHP_OS_FAMILY === 'Windows' ? substr(getcwd(), 0, 3) : '/';

        $pathPrefixer = new PathPrefixer($localFilesystemLocation, DIRECTORY_SEPARATOR);

        $symlinkProtectFilesystemAdapter = new SymlinkProtectFilesystemAdapter(
            null,
            $pathPrefixer,
            $this->logger
        );

        $this->filesystem = new Filesystem(
            $symlinkProtectFilesystemAdapter,
            [
                Config::OPTION_DIRECTORY_VISIBILITY => 'public',
            ],
            null,
            $pathPrefixer
        );
    }

    /**
     * @param InputInterface $input The command line input to check for `--debug`, `--silent` etc.
     * @param OutputInterface $output The Symfony object that actually prints the messages.
     */
    protected function getIOLogger(InputInterface $input, OutputInterface $output): LoggerInterface
    {
        $isDryRun = isset($this->config) && $this->config->isDryRun();

        // Who would want to dry-run without output?
        if (!$isDryRun && $input->hasOption('silent') && $input->getOption('silent') !== false) {
            return new NullLogger();
        }

        $logLevel = [LogLevel::NOTICE => OutputInterface::VERBOSITY_NORMAL];

        if ($input->hasOption('info') && $input->getOption('info') !== false) {
            $logLevel[LogLevel::INFO]= OutputInterface::VERBOSITY_NORMAL;
        }

        if ($isDryRun || ($input->hasOption('debug') && $input->getOption('debug') !== false)) {
            $logLevel[LogLevel::INFO]= OutputInterface::VERBOSITY_NORMAL;
            $logLevel[LogLevel::DEBUG]= OutputInterface::VERBOSITY_NORMAL;
        }

        return isset($this->logger) && $this->logger instanceof \Psr\Log\Test\TestLogger
            ? $this->logger
            : new ConsoleLogger($output, $logLevel);
    }

    protected function getReadOnlyFileSystem(FileSystem $filesystem): FileSystem
    {
        $normalizer = new WhitespacePathNormalizer();
        $normalizer = new StripProtocolPathNormalizer(['mem'], $normalizer);

        $pathPrefixer = new PathPrefixer('mem://', '/');

        $this->filesystem =
            new FileSystem(
                new ReadOnlyFileSystem(
                    $this->filesystem->getAdapter(),
                ),
                [],
                $normalizer,
                $pathPrefixer
            );

        /**
         * Register a file stream mem:// to handle file operations by third party libraries.
         *
         * @var FilesystemRegistry $registry
         */
        $registry = \Elazar\Flystream\ServiceLocator::get(\Elazar\Flystream\FilesystemRegistry::class);
        $registry->register('mem', $this->filesystem);

        return $filesystem;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     * @see Command::execute()
     *
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->setLogger($this->getIOLogger($input, $output));

        $workingDir       = getcwd() . '/';
        $this->workingDir = $workingDir;

        try {
            $this->logger->notice('Starting... ' /** version */); // + PHP version

            $this->loadProjectComposerPackage();
            $this->loadConfigFromComposerJson();
            $this->updateConfigFromCli($input);

            if ($this->config->isDryRun()) {
                $this->filesystem = $this->getReadOnlyFileSystem($this->filesystem);

                $this->setLogger($this->getIOLogger($input, $output));
            }

            $logger = new Logger('logger');
            $logger->pushProcessor(new PsrLogMessageProcessor());
            $logger->pushProcessor(new RelativeFilepathLogProcessor($this->filesystem));
            $logger->pushHandler(new PsrHandler($this->getIOLogger($input, $output)));
            $this->setLogger($logger);

            $this->buildDependencyList();

            $this->enumerateFiles();

            $this->discoveredSymbols = new DiscoveredSymbols();

            $this->enumeratePsr4Namespaces();
            $this->scanFiles();
            $this->determineChanges();

            $this->analyseFilesToCopy();
            $this->copyFiles();

            $this->performReplacements();

            $this->performReplacementsInProjectFiles();

            $this->addLicenses();

            $this->cleanUp();

            $this->generateAutoloader();

            // After files have been deleted, we may need aliases.
            $this->generateAliasesFile();

            $this->logger->notice('Done');
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


        $composerFilePath = $this->filesystem->normalize($this->workingDir . Factory::getComposerFile());
        $defaultComposerFilePath = $this->filesystem->normalize($this->workingDir . 'composer.json');
        if ($composerFilePath !== $defaultComposerFilePath) {
            $this->logger->info('Using: ' . $composerFilePath);
        }

        $composerFilePath = $this->filesystem->normalize($composerFilePath);
        $composerFilePath = $this->filesystem->prefixPath($composerFilePath);
        $this->projectComposerPackage = new ProjectComposerPackage($composerFilePath);

        // TODO: Print the config that Strauss is using.
        // Maybe even highlight what is default config and what is custom config.
    }

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

    /**
     * 2. Built flat list of packages and dependencies.
     *
     * 2.1 Initiate getting dependencies for the project composer.json.
     *
     * @see DependenciesCommand::flatDependencyTree
     */
    protected function buildDependencyList(): void
    {
        $this->logger->notice('Building dependency list...');

        $this->dependenciesEnumerator = new DependenciesEnumerator(
            $this->config,
            $this->filesystem,
            $this->logger
        );
        $this->flatDependencyTree = $this->dependenciesEnumerator->getAllDependencies();

        $this->config->setPackagesToCopy(
            array_filter($this->flatDependencyTree, function ($dependency) {
                return !in_array($dependency, $this->config->getExcludePackagesFromCopy());
            },
            ARRAY_FILTER_USE_KEY)
        );

        $this->config->setPackagesToPrefix(
            array_filter($this->flatDependencyTree, function ($dependency) {
                return !in_array($dependency, $this->config->getExcludePackagesFromPrefixing());
            },
            ARRAY_FILTER_USE_KEY)
        );

        foreach ($this->flatDependencyTree as $dependency) {
            // Sort of duplicating the logic above.
            $dependency->setCopy(
                !in_array($dependency, $this->config->getExcludePackagesFromCopy())
            );

            if ($this->config->isDeleteVendorPackages()) {
                $dependency->setDelete(true);
            }
        }

        // TODO: Print the dependency tree that Strauss has determined.

        $symlinkedDependencies = array_filter($this->flatDependencyTree, fn ($dependency) => $dependency->getPackageAbsolutePath() !== $dependency->getRealPath());

        if (!empty($symlinkedDependencies) &&
            ($this->config->isDeleteVendorFiles() || ($this->config->getTargetDirectory() === $this->config->getVendorDirectory()))
        ) {
            $list = implode(
                ', ',
                array_map(
                    fn($dependency) => $dependency->getPackageName(),
                    $symlinkedDependencies
                )
            );
            $this->logger->error(
                sprintf(
                    'Symlinked package%s detected: %s',
                    count($symlinkedDependencies) ? 's' : '',
                    $list
                )
            );
            // https://stackoverflow.com/a/65009324/336146
            $this->logger->notice('Use `COMPOSER_MIRROR_PATH_REPOS=1 composer install` to copy symlinked packages to vendor directory.');
            throw new Exception();
        }
    }


    protected function enumerateFiles(): void
    {
        $this->logger->notice('Enumerating files...');

        $fileEnumerator = new FileEnumerator(
            $this->config,
            $this->filesystem,
            $this->logger
        );

        $this->discoveredFiles = $fileEnumerator->compileFileListForDependencies($this->flatDependencyTree);
    }

    /**
     * TODO: currently this must run after ::determineChanges() so the discoveredSymbols object exists,
     * but logically it should run first.
     */
    protected function enumeratePsr4Namespaces(): void
    {
        foreach ($this->config->getPackagesToPrefix() as $package) {
            $autoloadKey = $package->getAutoload();
            if (! isset($autoloadKey['psr-4'])) {
                continue;
            }

            $psr4autoloadKey = $autoloadKey['psr-4'];
            $namespaces = array_keys($psr4autoloadKey);

            $file = new File($package->getPackageAbsolutePath() . 'composer.json', '../composer.json');

            foreach ($namespaces as $namespace) {
                // TODO: log.
                $symbol = new NamespaceSymbol(trim($namespace, '\\'), $file);
                // TODO: respect all config options.
//              $symbol->setReplacement($this->config->getNamespacePrefix() . '\\' . trim($namespace, '\\'));
                $this->discoveredSymbols->add($symbol);
            }
        }
    }

    // 4. Determine namespace and classname changes
    protected function scanFiles(): void
    {
        $this->logger->notice('Enumerating autoload files...');

        $autoloadFilesEnumerator = new AutoloadedFilesEnumerator(
            $this->config,
            $this->filesystem,
            $this->logger
        );
        $autoloadFilesEnumerator->markFilesForInclusion($this->flatDependencyTree);
        $autoloadFilesEnumerator->markFilesForExclusion($this->discoveredFiles);

        $this->logger->notice('Scanning files...');

        $fileScanner = new FileSymbolScanner(
            $this->config,
            $this->discoveredSymbols,
            $this->filesystem,
            $this->logger
        );

        $fileScanner->findInFiles($this->discoveredFiles);
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

    protected function analyseFilesToCopy(): void
    {
        (new FileCopyScanner($this->config, $this->filesystem, $this->logger))->scanFiles($this->discoveredFiles);
    }

    protected function copyFiles(): void
    {

        if ($this->config->getTargetDirectory() === $this->config->getVendorDirectory()) {
            // Nothing to do.
            return;
        }

        $this->logger->notice('Copying files...');

        $copier = new Copier(
            $this->discoveredFiles,
            $this->config,
            $this->filesystem,
            $this->logger
        );


        $copier->prepareTarget();
        $copier->copy();

        foreach ($this->flatDependencyTree as $package) {
            if ($package->isCopy()) {
                $package->setDidCopy(true);
            }
        }

        $installedJson = new InstalledJson(
            $this->config,
            $this->filesystem,
            $this->logger
        );
        $installedJson->copyInstalledJson();
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

        $this->replacer->replaceInFiles($this->discoveredSymbols, $this->discoveredFiles->getFiles());
    }

    protected function performReplacementsInProjectFiles(): void
    {
        // TODO: this doesn't do tests?!
        $relativeCallSitePaths =
            $this->config->getUpdateCallSites()
            ?? $this->projectComposerPackage->getFlatAutoloadKey();

        if (empty($relativeCallSitePaths)) {
            return;
        }

        $callSitePaths = array_map(
            fn($path) => $this->workingDir . $path,
            $relativeCallSitePaths
        );

        $projectReplace = new Prefixer(
            $this->config,
            $this->filesystem,
            $this->logger
        );

        $fileEnumerator = new FileEnumerator(
            $this->config,
            $this->filesystem,
            $this->logger
        );

        $projectFiles = $fileEnumerator->compileFileListForPaths($callSitePaths);

        $phpFiles = array_filter(
            $projectFiles->getFiles(),
            fn($file) => $file->isPhpFile()
        );

        $phpFilesAbsolutePaths = array_map(
            fn($file) => $file->getSourcePath(),
            $phpFiles
        );

        // TODO: Warn when a file that was specified is not found
        // $this->logger->warning('Expected file not found from project autoload: ' . $absolutePath);

        $projectReplace->replaceInProjectFiles($this->discoveredSymbols, $phpFilesAbsolutePaths);
    }

    protected function addLicenses(): void
    {
        $this->logger->notice('Adding licenses...');

        $author = $this->projectComposerPackage->getAuthor();

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

    /**
     * 6. Generate autoloader.
     */
    protected function generateAutoloader(): void
    {
        if (isset($this->projectComposerPackage->getAutoload()['classmap'])
            && in_array(
                $this->config->getTargetDirectory(),
                $this->projectComposerPackage->getAutoload()['classmap'],
                true
            )
        ) {
            $this->logger->notice('Skipping autoloader generation as target directory is in Composer classmap. Run `composer dump-autoload`.');
            return;
        }

        $this->logger->notice('Generating autoloader...');

        $allFilesAutoloaders = $this->dependenciesEnumerator->getAllFilesAutoloaders();
        $filesAutoloaders = array();
        foreach ($allFilesAutoloaders as $packageName => $packageFilesAutoloader) {
            if (in_array($packageName, $this->config->getExcludePackagesFromCopy())) {
                continue;
            }
            $filesAutoloaders[$packageName] = $packageFilesAutoloader;
        }

        $classmap = new Autoload(
            $this->config,
            $filesAutoloaders,
            $this->filesystem,
            $this->logger
        );

        $classmap->generate($this->flatDependencyTree, $this->discoveredSymbols);
    }

    /**
     * When namespaces are prefixed which are used by both require and require-dev dependencies,
     * the require-dev dependencies need class aliases specified to point to the new class names/namespaces.
     */
    protected function generateAliasesFile(): void
    {
        if (!$this->config->isCreateAliases()) {
            return;
        }

        $this->logger->notice('Generating aliases file...');

        $aliases = new Aliases(
            $this->config,
            $this->filesystem,
            $this->logger
        );
        $aliases->writeAliasesFileForSymbols($this->discoveredSymbols);

        $vendorComposerAutoload = new VendorComposerAutoload(
            $this->config,
            $this->filesystem,
            $this->logger
        );
        $vendorComposerAutoload->addAliasesFileToComposer();
        $vendorComposerAutoload->addVendorPrefixedAutoloadToVendorAutoload();
    }

    /**
     * 7.
     * Delete source files if desired.
     * Delete empty directories in destination.
     */
    protected function cleanUp(): void
    {

        $this->logger->notice('Cleaning up...');

        $cleanup = new Cleanup(
            $this->config,
            $this->filesystem,
            $this->logger
        );

        // This will check the config to check should it delete or not.
        $cleanup->deleteFiles($this->flatDependencyTree, $this->discoveredFiles);

        $cleanup->cleanupVendorInstalledJson($this->flatDependencyTree, $this->discoveredSymbols);
        if ($this->config->isDeleteVendorFiles() || $this->config->isDeleteVendorPackages()) {
            // Rebuild the autoloader after cleanup.
            // This is needed because cleanup may have deleted files that were in the autoloader.
            $cleanup->rebuildVendorAutoloader();
        }
    }
}
