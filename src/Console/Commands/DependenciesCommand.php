<?php

namespace BrianHenryIE\Strauss\Console\Commands;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\DeepDependenciesCollection;
use BrianHenryIE\Strauss\Composer\DependenciesCollection;
use BrianHenryIE\Strauss\Composer\ProjectComposerPackage;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Pipeline\Aliases\Aliases;
use BrianHenryIE\Strauss\Pipeline\Autoload;
use BrianHenryIE\Strauss\Pipeline\Autoload\Psr0;
use BrianHenryIE\Strauss\Pipeline\Autoload\VendorComposerAutoload;
use BrianHenryIE\Strauss\Pipeline\AutoloadedEnumerator;
use BrianHenryIE\Strauss\Pipeline\ChangeEnumerator;
use BrianHenryIE\Strauss\Pipeline\ChangePlanner;
use BrianHenryIE\Strauss\Pipeline\Cleanup\Cleanup;
use BrianHenryIE\Strauss\Pipeline\Cleanup\InstalledJson;
use BrianHenryIE\Strauss\Pipeline\Copier;
use BrianHenryIE\Strauss\Pipeline\DependenciesEnumerator;
use BrianHenryIE\Strauss\Pipeline\FileCopyScanner;
use BrianHenryIE\Strauss\Pipeline\FileEnumerator;
use BrianHenryIE\Strauss\Pipeline\FileSymbolScanner;
use BrianHenryIE\Strauss\Pipeline\Parallel\ParallelConfig;
use BrianHenryIE\Strauss\Pipeline\Parallel\WorkerPool;
use BrianHenryIE\Strauss\Pipeline\Licenser;
use BrianHenryIE\Strauss\Pipeline\MarkFilesExcludedFromChanges;
use BrianHenryIE\Strauss\Pipeline\MarkSymbolsForRenaming;
use BrianHenryIE\Strauss\Pipeline\Prefixer;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use BrianHenryIE\Strauss\Types\Psr0NamespaceSymbol;
use Composer\Factory;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DependenciesCommand extends AbstractRenamespacerCommand
{
    /** @var Prefixer */
    protected Prefixer $replacer;

    protected DependenciesEnumerator $dependenciesEnumerator;

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
     * Set name and description, add CLI arguments, call parent class to add dry-run, verbosity options.
     *
     * @used-by \Symfony\Component\Console\Command\Command::__construct
     * @override {@see \Symfony\Component\Console\Command\Command::configure()} empty method.
     *
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
            'parallel',
            null,
            4,
            'Analyse files in worker processes: true (one per spare CPU), a maximum number of workers, or false',
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

        /**
         * When run via. `strauss.phar`, classes such as `InstalledVersions` are prefixed, but when installed
         * via Composer, the unprefixed version is used.
         *
         * TODO: deduplicate code with {@see AbstractRenamespacerCommand}.
         */
        $symfonyVersion = class_exists(\BrianHenryIE\Strauss\Composer\InstalledVersions::class)
            ? \BrianHenryIE\Strauss\Composer\InstalledVersions::getVersion('symfony/console')
            : \Composer\InstalledVersions::getVersion('symfony/console');

        if (is_string($symfonyVersion) && version_compare($symfonyVersion, '7.2', '<')) {
            $this->addOption(
                'silent',
                's',
                4,
                'output level',
                false
            );
        }

        parent::configure();
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
//        $this->setLogger($this->getIOLogger($input, $output));

        try {
            $this->logger->notice('Starting... '/** version */); // + PHP version
            $startedAt = microtime(true);

            $this->loadProjectComposerPackage();
            $this->loadConfigFromComposerJson();
            $this->updateConfigFromCli($input);

            // Checks dry-run, replaces filesystem and logger.
            parent::execute($input, $output);

            $this->timed('buildDependencyList');

            $this->timed('enumerateFiles');

            $this->discoveredSymbols = new DiscoveredSymbols();

            $this->timed('enumeratePsrNamespaces');
            $this->timed('scanFilesForSymbols');
            $this->timed('enumerateAutoloadedFiles');
            $this->timed('analyseFilesToCopy');
            $this->timed('markSymbolsForRenaming');
            $this->timed('determineChanges');
            $this->timed('markFilesExcludedFromChanges');
            $this->timed('planChanges');

            $this->timed('setPsr0TargetDirectory');

            $this->timed('copyFiles');

            $this->timed('performReplacements');

            $this->timed('performReplacementsInProjectFiles');

            $this->timed('addLicenses');

            $this->timed('cleanUp');

            $this->timed('generateAutoloader');

            // After files have been deleted, we may need aliases.
            $this->timed('generateAliasesFile');

            $this->timed('prefixComposerAutoloadFiles');

            $this->logger->notice('Done in {seconds}s', ['seconds' => number_format(microtime(true) - $startedAt, 2)]);
        } catch (Exception $e) {
            $this->logger->error($e->getMessage() . ' in ' . $e->getFile() . ' ' . $e->getLine());
            $this->logger->error('Please submit a bug report with a minimally reproducing composer.json and logs from running strauss --debug');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
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

    protected function setPsr0TargetDirectory(): void
    {
        (new Psr0($this->filesystem, $this->logger))->setTargetDirectory(
            $this->flatDependencyTree,
            $this->discoveredFiles,
            $this->discoveredSymbols
        );
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
            array_filter(
                $this->flatDependencyTree->toArray(),
                function ($dependency) {
                    return !in_array($dependency, $this->config->getExcludePackagesFromCopy());
                },
                ARRAY_FILTER_USE_KEY
            )
        );

        $this->config->setPackagesToPrefix(
            array_filter($this->flatDependencyTree->toArray(), function ($dependency) {
                return !in_array($dependency, $this->config->getExcludePackagesFromPrefixing());
            },
            ARRAY_FILTER_USE_KEY)
        );

        foreach ($this->flatDependencyTree->toArray() as $dependency) {
            // Sort of duplicating the logic above.
            $dependency->setCopy(
                !in_array($dependency->getPackageName(), $this->config->getExcludePackagesFromCopy())
            );

            if ($this->config->isDeleteVendorPackages()) {
                $dependency->setDelete(true);
            }
        }

        // TODO: Print the dependency tree that Strauss has determined.

        $symlinkedDependencies = array_filter(
            $this->flatDependencyTree->toArray(),
            fn ($dependency) => !is_null($dependency->getRealPath()) && !str_starts_with($dependency->getRealPath(), $this->config->getProjectAbsolutePath())
        );

        if (!empty($symlinkedDependencies) &&
            ($this->config->isDeleteVendorFiles() || ($this->config->getAbsoluteTargetDirectory() === $this->config->getAbsoluteVendorDirectory()))
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
                    count($symlinkedDependencies) > 1 ? 's' : '',
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

    protected function enumeratePsrNamespaces(): void
    {
        foreach ($this->config->getPackagesToPrefix() as $package) {
            $autoloadTypes = $package->getAutoload();
            foreach (array_keys($autoloadTypes) as $autoloadKeyType) {
                switch ($autoloadKeyType) {
                    case 'psr-0':
                        // Fall-through.
                    case 'psr-4':
                        $namespaces = array_keys($autoloadTypes[$autoloadKeyType]);

                        foreach ($namespaces as $namespace) {
                            // TODO: log.

                            $symbol = $autoloadKeyType === 'psr-0'
                                ? new Psr0NamespaceSymbol(trim($namespace, '\\'))
                                : new NamespaceSymbol(trim($namespace, '\\'));

                            $symbol->setIsAutoloaded(true);
                            $symbol->setDoRename(true);

                            $this->discoveredSymbols->add($symbol);
                            $symbol->addDependency($package);
                            $package->addDiscoveredSymbol($symbol);
                        }
                        break;
                    default:
                        break;
                }
            }
        }
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

    /**
     * Determine every edit to make to every file, from the symbols marked for renaming and each file's code map,
     * before any file is copied or modified.
     */
    protected function planChanges(): void
    {
        $this->logger->notice('Planning changes to files...');

        $changePlanner = new ChangePlanner($this->logger);
        $changePlanner->planInFiles(
            $this->getDiscoveredSymbolsWithComposerRuntime(),
            $this->discoveredFiles->getFiles()
        );
    }

    /**
     * The discovered symbols, plus the classes Composer itself provides at runtime (`Composer\InstalledVersions`,
     * `Composer\Autoload\ClassLoader`), which will be prefixed when the autoloader is generated.
     *
     * Packages use those classes without requiring `composer-runtime-api`, so every package is assumed to depend
     * on them. Planning with them here means {@see self::prefixComposerAutoloadFiles()} only needs to change
     * Composer's own files, not every file again.
     *
     * They are not added to {@see self::$discoveredSymbols}: they are not the project's dependencies' symbols, and
     * should not get aliases etc.
     */
    protected function getDiscoveredSymbolsWithComposerRuntime(): DiscoveredSymbols
    {
        // Without the classmap output, no prefixed copy of Composer's files is generated.
        if (!$this->config->isClassmapOutput()) {
            return $this->discoveredSymbols;
        }

        $prefixer = new Prefixer($this->config, $this->filesystem, $this->logger);

        // The project's own Composer files declare the same classes the generated, prefixed, copies will.
        $composerRuntimeSymbols = $prefixer->getComposerRuntimeSymbols(
            $prefixer->getComposerRuntimeFiles($this->config->getAbsoluteVendorDirectory() . '/composer')
        );

        // A copy of the collection (the symbols themselves are shared), so the additions are only seen by planning.
        // Not rebuilt via `::toArray()`, which loses symbols of different types with the same name, e.g. a class
        // `WPGraphQL` and a namespace `WPGraphQL`.
        $symbols = clone $this->discoveredSymbols;

        foreach ($composerRuntimeSymbols->toArray() as $symbol) {
            // The generated autoloader classes (`ComposerAutoloaderInit...`) are only used in Composer's own files.
            if ($symbol->isGlobal()) {
                continue;
            }
            // E.g. when `composer/composer` is itself being prefixed: its symbols were already discovered.
            if ($symbols->has($symbol)) {
                continue;
            }
            $symbols->add($symbol);
        }

        return $symbols;
    }

    protected function markFilesExcludedFromChanges(): void
    {
        $this->logger->notice('Scanning files to omit from changes...');

        $markFilesExcludedFromChanges = new MarkFilesExcludedFromChanges(
            $this->config,
            $this->logger
        );

        $markFilesExcludedFromChanges->scanDiscoveredFiles($this->discoveredFiles);
    }

    protected function analyseFilesToCopy(): void
    {
        (new FileCopyScanner($this->config, $this->filesystem, $this->logger))->scanFiles($this->discoveredFiles);
    }

    protected function copyFiles(): void
    {

        if ($this->config->isTargetDirectoryVendor()) {
            // Only PSR-0 files need to be moved when target is vendor, otherwise everything is copied.
            foreach ($this->discoveredFiles->getPsr0() as $file) {
                if ($file->getSourcePath() === $file->getTargetAbsolutePath()) {
                    continue;
                }
                $this->filesystem->move(
                    $file->getSourcePath(),
                    $file->getTargetAbsolutePath()
                );
                // TODO: delete empty directories.
            }

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

        $this->replacer->replaceInFiles(
            $this->discoveredSymbols,
            $this->discoveredFiles->getFiles()
        );
    }

    /**
     * Update a project's /src/* files where they call the newly renamed /vendor/* classes etc.
     */
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
            fn($path) => $this->workingDir . '/' . $path,
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

        // TODO: Warn when a file that was specified is not found
        // $this->logger->warning('Expected file not found from project autoload: ' . $absolutePath);

        $projectReplace->replaceInProjectFiles($this->discoveredSymbols, $projectFiles);
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
                $this->config->getAbsoluteTargetDirectory(),
                array_map(
                    fn(string $entry) => trim($entry, '\\/'),
                    $this->projectComposerPackage->getAutoload()['classmap']
                ),
                true
            )
        ) {
            $this->logger->notice('Skipping autoloader generation as target directory is in Composer classmap. Run `composer dump-autoload`.');
            return;
        }

        $this->logger->notice('Generating autoloader...');

        $autoload = new Autoload(
            $this->config,
            [],
            $this->filesystem,
            $this->logger
        );

        $autoload->generate($this->flatDependencyTree, $this->discoveredSymbols);
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

    protected function prefixComposerAutoloadFiles() : void
    {

        $this->replacer->prefixComposerAutoloadFiles($this->config->getAbsoluteTargetDirectory());
    }

    /**
     *
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
