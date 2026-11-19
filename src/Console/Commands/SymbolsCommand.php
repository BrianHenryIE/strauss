<?php

/**
 * List the symbols (namespaces, classes, functions, constants, interfaces, traits and enums) defined in PHP files.
 *
 * Usage:
 *
 * ```
 * # Every symbol defined in the current working directory.
 * strauss list-symbols
 *
 * # Several files and directories, relative to the working directory or absolute.
 * strauss list-symbols src vendor/psr/log my-plugin.php
 *
 * # Only the namespaces, one per line, e.g. to choose `namespace_replacement_patterns`.
 * strauss list-symbols vendor --type=namespace
 *
 * # Several types, comma separated or repeated.
 * strauss list-symbols vendor -t class,interface
 * strauss list-symbols vendor --type=trait --type=enum
 *
 * # Only the symbols in the global namespace, i.e. those that could collide with another plugin's.
 * strauss list-symbols vendor --global
 *
 * # Global functions, for piping.
 * strauss list-symbols vendor --global --type=function | sort | uniq
 *
 * # Log each file scanned and symbol found.
 * strauss list-symbols vendor --info
 * ```
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
 * Registered as `list-symbols`. Enumerates the files in the given paths with {@see FileEnumerator}, scans them with
 * {@see FileSymbolScanner}, and prints each symbol's fully qualified name, sorted, one per line. Nothing is changed.
 *
 * With one `--type`, only the names are printed, for piping; otherwise each line is the type then the name.
 * With `--global`, only classes, interfaces, traits, enums, functions and constants outside any namespace are listed.
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
        $this->setName('list-symbols');
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

        $this->addOption(
            'global',
            'g',
            InputOption::VALUE_NONE,
            'Only list symbols in the global namespace.'
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

            $namesByType = $this->getSymbolNamesByType(
                $this->discoveredSymbols,
                $types,
                true === $input->getOption('global')
            );
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
     * @param bool              $onlyGlobal        Only list symbols in the global namespace, so no namespaces.
     *
     * @return array<string, string[]> Sorted fully qualified names, keyed by type in the order of {@see self::TYPES}.
     */
    protected function getSymbolNamesByType(DiscoveredSymbols $discoveredSymbols, array $types, bool $onlyGlobal = false): array
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
                fn(DiscoveredSymbol $symbol): bool => $symbol instanceof NamespaceSymbol
                    // The global namespace is not defined by any file, and any other namespace is not global.
                    ? false === $onlyGlobal && !$symbol->isGlobal()
                    : false === $onlyGlobal || $symbol->isGlobal()
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
