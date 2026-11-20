<?php

/**
 * Move a project's global classes into a namespace. (in-place)
 *
 * Usage: `strauss namespace-globals --namespace="Company\\Project" --strip-prefix=HTMLPurifier_ --paths=library --update-call-sites=tests`
 *
 * @package brianhenryie/strauss
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Console\Commands;

use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\Pipeline\ClassFileRenamer;
use BrianHenryIE\Strauss\Pipeline\FileEnumerator;
use BrianHenryIE\Strauss\Pipeline\GlobalsNamespacer;
use BrianHenryIE\Strauss\Pipeline\Parallel\WorkerPool;
use BrianHenryIE\Strauss\Pipeline\Prefixer;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use Composer\Util\Platform;
use Exception;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Registered as `namespace-globals`. The global classes, interfaces, traits and enums declared in `--paths` are moved
 * into `--namespace` by {@see GlobalsNamespacer}: each file gains a `namespace` declaration, and every use of a moved
 * name, in `--paths` and `--update-call-sites`, is updated. Functions and `const` constants move with their file.
 *
 * Files are not moved, unless `--rename-files` names a convention: then each file which declares one class is renamed
 * for its class and moved to the directory of its namespace, by {@see ClassFileRenamer}.
 */
class NamespaceGlobalsCommand extends AbstractRenamespacerCommand
{
    /**
     * @var string[] Absolute paths whose global symbols are moved.
     */
    protected array $symbolPaths = [];

    /**
     * @var string[] Absolute paths whose files are updated: the symbol paths and the call sites.
     */
    protected array $updatePaths = [];

    /**
     * @var string[] The `--paths` as given, for the autoload advice.
     */
    protected array $symbolPathsInput = [];

    protected string $namespace = '';

    /**
     * @var string[]
     */
    protected array $stripPrefixes = [];

    /**
     * The most namespace segments to make from a class name's `_` separated segments; null for no limit.
     */
    protected ?int $levels = null;

    /**
     * One of {@see ClassFileRenamer::CONVENTIONS}, or null to leave the files where they are.
     */
    protected ?string $renameFilesConvention = null;

    /**
     * @used-by \Symfony\Component\Console\Command\Command::__construct
     *
     * @return void
     */
    protected function configure()
    {
        $this->setName('namespace-globals');
        $this->setDescription('Move global classes, interfaces, traits and enums into a namespace.');
        $this->setHelp(
            'E.g. `strauss namespace-globals --namespace="Company\\Project" --strip-prefix=Project_ --paths=includes`'
            . ' moves `Project_Admin_Settings` in `includes/` to `Company\\Project\\Admin\\Settings`.'
        );

        $this->addOption(
            'namespace',
            null,
            InputOption::VALUE_REQUIRED,
            'The namespace to move the global symbols into, e.g. `Company\\Project`.'
        );

        $this->addOption(
            'strip-prefix',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'A class name prefix to remove, e.g. `Project_`; the rest of the name\'s `_` separated segments become namespace segments. Repeatable.'
        );

        $this->addOption(
            'levels',
            null,
            InputOption::VALUE_REQUIRED,
            'The most namespace segments to make from a class name\'s `_` separated segments, after `--strip-prefix`; the rest keep their `_`.'
            . ' With `--levels=1`, `Project_License_Post_Type` is `License\\Post_Type`. Default is no limit: `License\\Post\\Type`.'
        );

        $this->addOption(
            'paths',
            null,
            InputOption::VALUE_REQUIRED,
            'Comma separated files and directories whose global symbols are moved. Default is the current working directory.'
        );

        $this->addOption(
            'update-call-sites',
            null,
            InputOption::VALUE_REQUIRED,
            'Comma separated files and directories which use the moved symbols, to update too.'
        );

        $this->addOption(
            'rename-files',
            null,
            InputOption::VALUE_REQUIRED,
            'Rename each file in `--paths` which declares one class for its class, and move it to the directory of its namespace:'
            . ' `psr4`, e.g. `Admin/Settings_Page.php`, or `wordpress`, e.g. `admin/class-settings-page.php`. Default is not to.'
            . ' The files git tracks are moved with `git mv`.'
            . ' Their paths written in the other files in the current directory, e.g. `phpstan.neon`, are updated.'
        );

        parent::configure();
    }

    /**
     * @param InputInterface  $input  Command-line arguments and options.
     * @param OutputInterface $output Console output for logging.
     *
     * @see Command::execute()
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            parent::execute($input, $output);

            $this->updateConfigFromCli($input);
            $this->discoveredSymbols = new DiscoveredSymbols();

            $this->runPipeline();
        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage());

            return Command::FAILURE;
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
            'determineChanges',
            'planChanges',
            'performReplacementsInProjectFiles',
            'renameClassFiles',
            'logAutoloadAdvice',
        ];
    }

    /**
     * @param InputInterface $input The `--namespace`, `--strip-prefix`, `--levels`, `--paths`, `--update-call-sites`
     *                              and `--rename-files` options.
     *
     * @throws InvalidArgumentException When `--namespace` is missing, `--levels` is not a whole number, or
     *                                  `--rename-files` is not a known convention.
     */
    protected function updateConfigFromCli(InputInterface $input): void
    {
        /**
 * @var ?string $namespace
*/
        $namespace = $input->getOption('namespace');
        if (empty($namespace) || empty(trim($namespace, '\\'))) {
            throw new InvalidArgumentException('The --namespace option is required, e.g. --namespace="Company\\Project".');
        }
        // E.g. `'Company\\Project'` typed in single quotes.
        $this->namespace = trim((string) preg_replace('/\\\\+/', '\\', $namespace), '\\');

        /**
 * @var string[] $stripPrefixes
*/
        $stripPrefixes = $input->getOption('strip-prefix');
        $this->stripPrefixes = $stripPrefixes;

        /**
 * @var ?string $levels
*/
        $levels = $input->getOption('levels');
        if (!is_null($levels) && 1 !== preg_match('/^\d+$/', $levels)) {
            throw new InvalidArgumentException('The --levels option must be a whole number, 0 or more, e.g. --levels=1.');
        }
        $this->levels = is_null($levels) ? null : (int) $levels;

        /**
 * @var ?string $paths
*/
        $paths = $input->getOption('paths');
        $this->symbolPathsInput = array_values(array_filter(array_map('trim', explode(',', $paths ?: '.'))));
        $this->symbolPaths = $this->toAbsolutePaths($paths ?: '.');

        /**
 * @var ?string $renameFiles
*/
        $renameFiles = $input->getOption('rename-files');
        if (!is_null($renameFiles) && !in_array($renameFiles, ClassFileRenamer::CONVENTIONS, true)) {
            throw new InvalidArgumentException(
                sprintf('The --rename-files option must be one of: %s.', implode(', ', ClassFileRenamer::CONVENTIONS))
            );
        }
        $this->renameFilesConvention = $renameFiles;

        /**
 * @var ?string $callSites
*/
        $callSites = $input->getOption('update-call-sites');
        $this->updatePaths = array_values(
            array_unique(array_merge($this->symbolPaths, $callSites ? $this->toAbsolutePaths($callSites) : []))
        );
    }

    /**
     * @param string $commaSeparatedPaths Paths relative to the current directory, or absolute.
     *
     * @return string[]
     */
    protected function toAbsolutePaths(string $commaSeparatedPaths): array
    {
        $cwd = rtrim(str_replace('\\', '/', Platform::getcwd()), '/');

        return array_values(
            array_map(
                function (string $path) use ($cwd): string {
                    $path = str_replace('\\', '/', $path);
                    if ('' !== FileSystem::getFsRoot($path)) {
                        return rtrim($path, '/');
                    }
                    return rtrim($cwd . '/' . preg_replace('#^\./#', '', $path), '/.');
                },
                array_filter(array_map('trim', explode(',', $commaSeparatedPaths)))
            )
        );
    }

    protected function enumerateFiles(): void
    {
        $this->logger->info('Enumerating files...');

        $fileEnumerator = new FileEnumerator($this->config, $this->filesystem, $this->logger);
        $this->discoveredFiles = $fileEnumerator->compileFileListForPaths($this->updatePaths);
    }

    protected function determineChanges(): void
    {
        $this->logger->notice('Determining changes...');

        (new GlobalsNamespacer($this->namespace, $this->stripPrefixes, $this->symbolPaths, $this->logger, $this->levels))
            ->determineReplacements($this->discoveredSymbols);
    }

    protected function performReplacementsInProjectFiles(): void
    {
        $this->logger->notice('Updating files...');

        $this->replacer = new Prefixer($this->config, $this->filesystem, $this->logger);
        $this->replacer->replaceInProjectFiles($this->discoveredSymbols, $this->discoveredFiles);
    }

    /**
     * With `--rename-files`, rename each class file in `--paths` for its class, and move it to the directory of its
     * namespace. The paths written in the files in `--paths` and `--update-call-sites`, and in the other PHP and text
     * files in the current directory, e.g. `phpstan.neon`, are updated.
     */
    protected function renameClassFiles(): void
    {
        if (is_null($this->renameFilesConvention)) {
            return;
        }

        $this->logger->notice('Renaming files...');

        $classFileRenamer = new ClassFileRenamer(
            $this->namespace,
            $this->renameFilesConvention,
            $this->symbolPaths,
            str_replace('\\', '/', Platform::getcwd()),
            $this->filesystem,
            $this->logger,
            // With `git mv`, for the files git tracks; not during `--dry-run`, when nothing is written.
            !$this->config->isDryRun()
        );

        $moves = $classFileRenamer->rename(
            array_map(fn(FileBase $file): string => $file->getSourcePath(), $this->discoveredFiles->getFiles())
        );

        $this->logger->notice('Renamed {count} files.', ['count' => count($moves)]);
    }

    /**
     * The moved classes are no longer found by a PSR-0 or `_` to `/` autoloader for their old names.
     */
    protected function logAutoloadAdvice(): void
    {
        $moved = count($this->discoveredSymbols->getToRename());

        if (ClassFileRenamer::CONVENTION_PSR4 === $this->renameFilesConvention) {
            $this->logger->notice(
                'Moved {count} symbols into {namespace}. Update the autoloader for their new names, e.g. in composer.json: "autoload": {"psr-4": {"{psr4Namespace}": [{entries}]}}, with "classmap" or "files" entries for the files which were not renamed, then run `composer dump-autoload`.',
                [
                    'count' => $moved,
                    'namespace' => $this->namespace,
                    'psr4Namespace' => str_replace('\\', '\\\\', $this->namespace) . '\\\\',
                    'entries' => implode(', ', array_map(fn(string $path): string => '"' . $path . '"', $this->symbolPathsInput)),
                ]
            );
            return;
        }

        $this->logger->notice(
            'Moved {count} symbols into {namespace}. Update the autoloader for their new names, e.g. in composer.json: "autoload": {"classmap": [{classmapEntries}]}, then run `composer dump-autoload`.',
            [
                'count' => $moved,
                'namespace' => $this->namespace,
                'classmapEntries' => implode(', ', array_map(fn(string $path): string => '"' . $path . '"', $this->symbolPathsInput)),
            ]
        );
    }

    /**
     * The files are analysed in this process.
     */
    protected function getWorkerPool(): ?WorkerPool
    {
        return null;
    }
}
