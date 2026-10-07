<?php
/**
 * Regenerates the autoloader in the target directory, without copying or prefixing any files again.
 *
 * Like `composer dump-autoload`: when `include_root_autoload` is enabled, the project's own `autoload` key is part
 * of the `vendor-prefixed/autoload.php` autoloader, so e.g. a class added to a `classmap` directory is not
 * autoloadable until the autoloader is regenerated.
 *
 * `strauss` (the `dependencies` command) must have been run first. Only the project's `composer.json` and the target
 * directory are needed: the `vendor` directory may be absent.
 *
 * @package brianhenryie/strauss
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Console\Commands;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Pipeline\Autoload;
use Exception;
use League\Flysystem\FilesystemException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DumpAutoloadCommand extends AbstractRenamespacerCommand
{
    /**
     * @used-by \Symfony\Component\Console\Command\Command::__construct
     *
     * @return void
     */
    protected function configure()
    {
        $this->setName('dump-autoload');
        $this->setAliases(['dumpautoload']);
        $this->setDescription('Regenerate the autoloader in the target directory.');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->loadProjectComposerPackage();
            $this->loadConfigFromComposerJson();
            $this->updateConfigFromCli($input);

            // Checks dry-run, replaces filesystem and logger.
            parent::execute($input, $output);

            $this->runPipeline();
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Only the steps of the `dependencies` pipeline needed to write the autoloader.
     *
     * @return string[]
     */
    protected function getPipelineSteps(): array
    {
        return [
            'loadPackagesFromTargetInstalledJson',
            'generateAutoloader',
            'prefixComposerAutoloadFiles',
        ];
    }

    /**
     * Determine the packages that were copied, and those that were prefixed, from the target directory's
     * `installed.json`, rather than from the `vendor` directory, which may since have been deleted.
     *
     * @see DependenciesCommand::buildDependencyList()
     *
     * @throws Exception
     * @throws FilesystemException
     */
    protected function loadPackagesFromTargetInstalledJson(): void
    {
        $installedJsonPath = $this->config->getAbsoluteTargetDirectory() . '/composer/installed.json';
        if (!$this->filesystem->fileExists($installedJsonPath)) {
            throw new Exception(
                'Expected installed.json does not exist: ' . $installedJsonPath . '. Run `strauss` first.'
            );
        }

        /** @var array{packages?: array<array{name: string}>} $installedJson */
        $installedJson = json_decode($this->filesystem->read($installedJsonPath), true, 512, JSON_THROW_ON_ERROR);

        $packages = [];
        foreach ($installedJson['packages'] ?? [] as $packageArray) {
            $packages[$packageArray['name']] = ComposerPackage::fromComposerJsonArray($packageArray);
        }

        $this->config->setPackagesToCopy($packages);

        $this->config->setPackagesToPrefix(
            array_filter(
                $packages,
                fn(string $packageName): bool => !in_array($packageName, $this->config->getExcludePackagesFromPrefixing(), true),
                ARRAY_FILTER_USE_KEY
            )
        );
    }

    /**
     * The target directory's `installed.json` was already updated when the packages were copied, so the autoload
     * files are regenerated from it as-is.
     */
    protected function generateAutoloader(): void
    {
        if (!$this->config->isClassmapOutput()) {
            $this->logger->notice('Not generating autoloader because classmap output is disabled.');
            return;
        }

        if (!$this->config->isIncludeRootAutoload()) {
            $this->logger->info('`include_root_autoload` is not enabled: the project\'s own autoload key will not be included.');
        }

        $this->logger->notice('Generating autoloader...');

        $autoload = new Autoload(
            $this->config,
            [],
            $this->filesystem,
            $this->logger
        );

        $autoload->dumpAutoload();
    }
}
