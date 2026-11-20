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

use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
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
 * name, in `--paths` and `--update-call-sites`, becomes fully qualified. Files are not moved, and functions and
 * constants stay global.
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
            'logAutoloadAdvice',
        ];
    }

    /**
     * @param InputInterface $input The `--namespace`, `--strip-prefix`, `--paths` and `--update-call-sites` options.
     *
     * @throws InvalidArgumentException When `--namespace` is missing.
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
 * @var ?string $paths
*/
        $paths = $input->getOption('paths');
        $this->symbolPathsInput = array_values(array_filter(array_map('trim', explode(',', $paths ?: '.'))));
        $this->symbolPaths = $this->toAbsolutePaths($paths ?: '.');

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

        (new GlobalsNamespacer($this->namespace, $this->stripPrefixes, $this->symbolPaths, $this->logger))
            ->determineReplacements($this->discoveredSymbols);
    }

    protected function performReplacementsInProjectFiles(): void
    {
        $this->logger->notice('Updating files...');

        $this->replacer = new Prefixer($this->config, $this->filesystem, $this->logger);
        $this->replacer->replaceInProjectFiles($this->discoveredSymbols, $this->discoveredFiles);
    }

    /**
     * The moved classes are no longer found by a PSR-0 or `_` to `/` autoloader for their old names.
     */
    protected function logAutoloadAdvice(): void
    {
        $moved = count($this->discoveredSymbols->getToRename());

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
