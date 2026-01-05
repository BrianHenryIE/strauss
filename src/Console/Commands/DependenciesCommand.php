<?php

namespace BrianHenryIE\Strauss\Console\Commands;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\ProjectComposerPackage;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
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
use BrianHenryIE\Strauss\Pipeline\MarkSymbolsForRenaming;
use BrianHenryIE\Strauss\Pipeline\Prefixer;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
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
        try {
            $this->logger->notice('Starting... '/** version */); // + PHP version

            $this->loadProjectComposerPackage();
            $this->loadConfigFromComposerJson();
            $this->updateConfigFromCli($input);

            parent::execute($input, $output);

            $this->buildDependencyList();

            $this->enumerateFiles();

            $this->discoveredSymbols = new DiscoveredSymbols();

            $this->enumeratePsr4Namespaces();
            $this->enumerateAutoloadedFiles();
            $this->scanFilesForSymbols();
            $this->analyseFilesToCopy();
            $this->markSymbolsForRenaming();
            $this->determineChanges();
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
     * Load the project's composer package using the current working directory.
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

        $this->projectComposerPackage = new ProjectComposerPackage('/'.$composerFilePath);

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
                !in_array($dependency->getPackageName(), $this->config->getExcludePackagesFromCopy())
            );

            if ($this->config->isDeleteVendorPackages()) {
                $dependency->setDelete(true);
            }
        }

        // TODO: Print the dependency tree that Strauss has determined.
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
                $symbol = new NamespaceSymbol(
                    trim($namespace, '\\'),
                    $file,
                    '\\',
                    $package
                );
                // TODO: respect all config options.
//              $symbol->setReplacement($this->config->getNamespacePrefix() . '\\' . trim($namespace, '\\'));
                $this->discoveredSymbols->add($symbol);
            }
        }
    }

    protected function enumerateAutoloadedFiles(): void
    {
        $this->logger->notice('Enumerating autoload files...');

        $autoloadFilesEnumerator = new AutoloadedFilesEnumerator(
            $this->config,
            $this->filesystem,
            $this->logger
        );
        $autoloadFilesEnumerator->scanForAutoloadedFiles($this->flatDependencyTree);
    }

    protected function scanFilesForSymbols(): void
    {
        $this->logger->notice('Scanning files...');

        $fileSymbolScanner = new FileSymbolScanner(
            $this->config,
            $this->discoveredSymbols,
            $this->filesystem,
            $this->logger
        );

        $fileSymbolScanner->findInFiles($this->discoveredFiles);
    }

    protected function markSymbolsForRenaming(): void
    {

        $markSymbolsForRenaming = new MarkSymbolsForRenaming(
            $this->config,
            $this->filesystem,
            $this->logger
        );

        $markSymbolsForRenaming->scanSymbols($this->discoveredSymbols);
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

        $this->replacer->replaceInFiles(
            $this->discoveredSymbols,
            $this->discoveredFiles->getFiles()
        );
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
