<?php

/**
 * List the symbols (namespaces, classes, functions, constants, interfaces, traits and enums) defined in PHP files.
 *
 * Usage: `strauss symbols src vendor/psr/log --type=namespace`
 *
 * @package brianhenryie/strauss
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Console\Commands;

use BrianHenryIE\Strauss\Pipeline\FileEnumerator;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\SymbolDefinition;
use BrianHenryIE\Strauss\Pipeline\FileSymbolScanner;
use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Exception;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Registered as `symbols`. Enumerates the files in the given paths with {@see FileEnumerator}, scans them with
 * {@see FileSymbolScanner}, and prints each symbol's fully qualified name, sorted, one per line. Nothing is changed.
 *
 * With one `--type`, only the names are printed, for piping; otherwise each line is the type then the name.
 */
class SymbolsCommand extends AbstractRenamespacerCommand
{
    /**
     * The types that can be listed, in the order they are printed.
     */
    public const TYPES = [
        SymbolDefinition::TYPE_NAMESPACE,
        SymbolDefinition::TYPE_CLASS,
        SymbolDefinition::TYPE_INTERFACE,
        SymbolDefinition::TYPE_TRAIT,
        SymbolDefinition::TYPE_ENUM,
        SymbolDefinition::TYPE_FUNCTION,
        SymbolDefinition::TYPE_CONSTANT,
    ];

    /**
     * Set name, description, arguments and options, call parent class to add dry-run, verbosity options.
     *
     * @used-by  \Symfony\Component\Console\Command\Command::__construct
     * @override {@see \Symfony\Component\Console\Command\Command::configure()} empty method.
     *
     * @return void
     */
    protected function configure()
    {
        $this->setName('symbols');
        $this->setDescription('List the namespaces, classes, functions etc. defined in the PHP files in a directory.');

        $this->addArgument(
            'paths',
            InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
            'Files and directories to scan. Default is the current working directory.'
        );

        $this->addOption(
            'type',
            't',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Only list symbols of this type, comma separated or repeated: ' . implode(', ', self::TYPES) . '.'
        );

        parent::configure();
    }

    /**
     * @param InputInterface  $input  Command-line arguments and options.
     * @param OutputInterface $output Console output the symbols are written to.
     *
     * @see Command::execute()
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            parent::execute($input, $output);

            $types = $this->getTypesFromInput($input);

            $this->config->setProjectAbsolutePath($this->workingDir);

            $fileEnumerator = new FileEnumerator($this->config, $this->filesystem, $this->logger);
            $this->discoveredFiles = $fileEnumerator->compileFileListForPaths($this->getPathsFromInput($input));

            $this->discoveredSymbols = new DiscoveredSymbols();
            $fileSymbolScanner = new FileSymbolScanner(
                $this->config,
                $this->discoveredSymbols,
                $this->filesystem,
                $this->logger
            );
            $fileSymbolScanner->findInFiles($this->discoveredFiles);

            $namesByType = $this->getSymbolNamesByType($this->discoveredSymbols, $types);
        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage());

            return Command::FAILURE;
        }

        foreach ($namesByType as $type => $names) {
            foreach ($names as $name) {
                $output->writeln(count($types) === 1 ? $name : sprintf('%-10s %s', $type, $name));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @param InputInterface $input The `paths` argument, relative to the working directory or absolute.
     *
     * @return string[] Absolute paths.
     */
    protected function getPathsFromInput(InputInterface $input): array
    {
        /**
         * @var string[] $inputPaths
         */
        $inputPaths = $input->getArgument('paths');

        if (empty($inputPaths)) {
            return [$this->workingDir];
        }

        return array_map(
            fn(string $path): string => $this->filesystem->normalizePath(
                $this->isAbsolutePath($path) ? $path : $this->workingDir . '/' . $path
            ),
            $inputPaths
        );
    }

    protected function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || 1 === preg_match('/^[a-zA-Z]:[\\\\\/]/', $path);
    }

    /**
     * @param InputInterface $input The `--type` option, repeated and/or comma separated.
     *
     * @return string[] The requested types, or all types when none were given.
     * @throws InvalidArgumentException When a type is not one of {@see self::TYPES}.
     */
    protected function getTypesFromInput(InputInterface $input): array
    {
        /**
         * @var string[] $inputTypes
         */
        $inputTypes = $input->getOption('type');

        $types = array_filter(
            array_map(
                fn(string $type): string => strtolower(trim($type)),
                explode(',', implode(',', $inputTypes))
            )
        );

        if (empty($types)) {
            return self::TYPES;
        }

        $unknownTypes = array_diff($types, self::TYPES);
        if (!empty($unknownTypes)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unknown type "%s", expected one of: %s.',
                    implode('", "', $unknownTypes),
                    implode(', ', self::TYPES)
                )
            );
        }

        return array_values(array_intersect(self::TYPES, $types));
    }

    /**
     * @param DiscoveredSymbols $discoveredSymbols The symbols found in the files.
     * @param string[]          $types             The types to list.
     *
     * @return array<string, string[]> Sorted fully qualified names, keyed by type in the order of {@see self::TYPES}.
     */
    protected function getSymbolNamesByType(DiscoveredSymbols $discoveredSymbols, array $types): array
    {
        $symbolsByType = [
            SymbolDefinition::TYPE_NAMESPACE => $discoveredSymbols->getNamespaces(),
            SymbolDefinition::TYPE_CLASS => $discoveredSymbols->getAllClasses(),
            SymbolDefinition::TYPE_INTERFACE => $discoveredSymbols->getDiscoveredInterfaces(),
            SymbolDefinition::TYPE_TRAIT => $discoveredSymbols->getDiscoveredTraits(),
            SymbolDefinition::TYPE_ENUM => $discoveredSymbols->getDiscoveredEnums(),
            SymbolDefinition::TYPE_FUNCTION => $discoveredSymbols->getDiscoveredFunctions(),
            SymbolDefinition::TYPE_CONSTANT => $discoveredSymbols->getConstants(),
        ];

        $namesByType = [];
        foreach ($types as $type) {
            $symbols = array_filter(
                $symbolsByType[$type]->toArray(),
                // The global namespace is not defined by any file.
                fn(DiscoveredSymbol $symbol): bool => !($symbol instanceof NamespaceSymbol && $symbol->isGlobal())
            );
            $names = array_map(
                fn(DiscoveredSymbol $symbol): string => $symbol->getOriginalFqdnName(),
                array_values($symbols)
            );
            sort($names);
            $namesByType[$type] = $names;
        }

        return $namesByType;
    }
}
