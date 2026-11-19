<?php

/**
 * Runs each usage example in the {@see SymbolsCommand} PhpDoc against a small project.
 *
 * Log lines are ignored, except in the `--info` example, so the tests pass when `RENAMESPACER_LOG` is set.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\Console\Commands\SymbolsCommand;
use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @coversNothing
 */
class SymbolsCommandFeatureTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $filesystem = $this->getFileSystem();

        $filesystem->write(
            $this->testsWorkingDir . '/my-plugin.php',
            <<<'PHP'
                <?php
                /**
                 * Plugin Name: My Plugin
                 */

                namespace MyPlugin;

                function init() {}
                PHP
        );

        $filesystem->write(
            $this->testsWorkingDir . '/src/Plugin.php',
            <<<'PHP'
                <?php
                namespace MyPlugin;

                class Plugin {}
                PHP
        );

        $filesystem->write(
            $this->testsWorkingDir . '/src/helpers.php',
            <<<'PHP'
                <?php
                trait My_Plugin_Trait {}

                function my_plugin_helper() {}
                PHP
        );

        $filesystem->write(
            $this->testsWorkingDir . '/vendor/psr/log/src/LoggerInterface.php',
            <<<'PHP'
                <?php
                namespace Psr\Log;

                interface LoggerInterface {}
                PHP
        );

        $filesystem->write(
            $this->testsWorkingDir . '/vendor/psr/log/src/LoggerTrait.php',
            <<<'PHP'
                <?php
                namespace Psr\Log;

                trait LoggerTrait {}
                PHP
        );

        $filesystem->write(
            $this->testsWorkingDir . '/vendor/psr/log/src/NullLogger.php',
            <<<'PHP'
                <?php
                namespace Psr\Log;

                class NullLogger implements LoggerInterface {}
                PHP
        );

        $filesystem->write(
            $this->testsWorkingDir . '/vendor/acme/status/src/Status.php',
            <<<'PHP'
                <?php
                namespace Acme\Status;

                enum Status: string {
                    case Active = 'active';
                }
                PHP
        );

        $filesystem->write(
            $this->testsWorkingDir . '/vendor/acme/legacy/legacy.php',
            <<<'PHP'
                <?php
                class Acme_Legacy {}

                if (!function_exists('acme_legacy_helper')) {
                    function acme_legacy_helper() {}
                }

                function acme_legacy_other() {}

                const ACME_LEGACY_VERSION = '1.0.0';
                PHP
        );

        $filesystem->write($this->testsWorkingDir . '/vendor/acme/legacy/README.md', 'namespace Not\Php;');
    }

    /**
     * @return string[] The output's lines, without log lines and blank lines.
     */
    protected function symbolLines(?string $output): array
    {
        return array_values(
            array_filter(
                array_map('rtrim', explode("\n", (string) $output)),
                fn(string $line): bool => '' !== $line && 1 !== preg_match('/^\s*\[\w+\]/', $line)
            )
        );
    }

    /**
     * `strauss list-symbols`
     */
    public function test_every_symbol_in_the_working_directory(): void
    {
        $exitCode = $this->runStrauss($output, 'list-symbols');

        self::assertSame(0, $exitCode, $output);
        self::assertSame(
            [
                'namespace  Acme\Status',
                'namespace  MyPlugin',
                'namespace  Psr\Log',
                'class      Acme_Legacy',
                'class      MyPlugin\Plugin',
                'class      Psr\Log\NullLogger',
                'interface  Psr\Log\LoggerInterface',
                'trait      My_Plugin_Trait',
                'trait      Psr\Log\LoggerTrait',
                'enum       Acme\Status\Status',
                'function   MyPlugin\init',
                'function   acme_legacy_helper',
                'function   acme_legacy_other',
                'function   my_plugin_helper',
                'constant   ACME_LEGACY_VERSION',
            ],
            $this->symbolLines($output)
        );
    }

    /**
     * `strauss list-symbols src vendor/psr/log my-plugin.php`
     */
    public function test_several_files_and_directories(): void
    {
        $exitCode = $this->runStrauss($output, 'list-symbols src vendor/psr/log my-plugin.php');

        self::assertSame(0, $exitCode, $output);
        self::assertSame(
            [
                'namespace  MyPlugin',
                'namespace  Psr\Log',
                'class      MyPlugin\Plugin',
                'class      Psr\Log\NullLogger',
                'interface  Psr\Log\LoggerInterface',
                'trait      My_Plugin_Trait',
                'trait      Psr\Log\LoggerTrait',
                'function   MyPlugin\init',
                'function   my_plugin_helper',
            ],
            $this->symbolLines($output)
        );
    }

    /**
     * The paths may be absolute.
     */
    public function test_absolute_path(): void
    {
        $exitCode = $this->runStrauss($output, 'list-symbols ' . $this->testsWorkingDir . '/src');

        self::assertSame(0, $exitCode, $output);
        self::assertSame(
            [
                'namespace  MyPlugin',
                'class      MyPlugin\Plugin',
                'trait      My_Plugin_Trait',
                'function   my_plugin_helper',
            ],
            $this->symbolLines($output)
        );
    }

    /**
     * `strauss list-symbols vendor --type=namespace`
     */
    public function test_only_namespaces(): void
    {
        $exitCode = $this->runStrauss($output, 'list-symbols vendor --type=namespace');

        self::assertSame(0, $exitCode, $output);
        self::assertSame(
            [
                'Acme\Status',
                'Psr\Log',
            ],
            $this->symbolLines($output)
        );
    }

    /**
     * `strauss list-symbols vendor -t class,interface`
     */
    public function test_several_types_comma_separated(): void
    {
        $exitCode = $this->runStrauss($output, 'list-symbols vendor -t class,interface');

        self::assertSame(0, $exitCode, $output);
        self::assertSame(
            [
                'class      Acme_Legacy',
                'class      Psr\Log\NullLogger',
                'interface  Psr\Log\LoggerInterface',
            ],
            $this->symbolLines($output)
        );
    }

    /**
     * `strauss list-symbols vendor --type=trait --type=enum`
     */
    public function test_several_types_repeated(): void
    {
        $exitCode = $this->runStrauss($output, 'list-symbols vendor --type=trait --type=enum');

        self::assertSame(0, $exitCode, $output);
        self::assertSame(
            [
                'trait      Psr\Log\LoggerTrait',
                'enum       Acme\Status\Status',
            ],
            $this->symbolLines($output)
        );
    }

    /**
     * `strauss list-symbols vendor --global`
     */
    public function test_only_global_symbols(): void
    {
        $exitCode = $this->runStrauss($output, 'list-symbols vendor --global');

        self::assertSame(0, $exitCode, $output);
        self::assertSame(
            [
                'class      Acme_Legacy',
                'function   acme_legacy_helper',
                'function   acme_legacy_other',
                'constant   ACME_LEGACY_VERSION',
            ],
            $this->symbolLines($output)
        );
    }

    /**
     * `strauss list-symbols vendor --global --type=function | sort | uniq`
     *
     * One type prints only the names, so they can be piped.
     */
    public function test_global_functions_for_piping(): void
    {
        $exitCode = $this->runStrauss($output, 'list-symbols vendor --global --type=function');

        self::assertSame(0, $exitCode, $output);
        self::assertSame(
            [
                'acme_legacy_helper',
                'acme_legacy_other',
            ],
            $this->symbolLines($output)
        );
    }

    /**
     * `strauss list-symbols vendor --info`
     */
    public function test_info_logs_each_file_and_symbol(): void
    {
        $exitCode = $this->runStrauss($output, 'list-symbols vendor --info');

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('[info]', $output);
        self::assertStringContainsString('legacy.php', $output);
        self::assertStringContainsString('Psr\Log\NullLogger', $output);
        self::assertContains('class      Psr\Log\NullLogger', $this->symbolLines($output));
    }

    /**
     * An unknown `--type` fails, listing the valid types.
     */
    public function test_unknown_type_fails(): void
    {
        $exitCode = $this->runStrauss($output, 'list-symbols vendor --type=namespaces');

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('Unknown type "namespaces"', $output);
        self::assertStringContainsString(implode(', ', SymbolsCommand::TYPES), $output);
    }
}
