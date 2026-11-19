<?php

/**
 * Prefixing in place (`target_directory: vendor`) a second time, when the prefixed package was not reinstalled, so its
 * files already declare the prefixed names.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/34
 * @see https://github.com/BrianHenryIE/strauss/issues/258
 * @see https://github.com/BrianHenryIE/strauss/issues/343
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @coversNothing
 */
class PrefixInPlaceTwiceFeatureTest extends IntegrationTestCase
{
    /**
     * The files of `acme/lib`, the package being prefixed, relative to its directory.
     */
    protected const LIB_FILES = ['functions.php', 'src/Widget.php'];

    /**
     * `acme/lib`: a global class, function and constant, autoloaded by `files`, and a namespaced class only autoloaded
     * by `classmap`. `acme/consumer`: not prefixed, uses them all.
     */
    protected function writeProject(): void
    {
        $composerJson = [
            'name' => 'strauss/prefix-in-place-twice',
            'repositories' => [
                ['type' => 'path', 'url' => './packages/lib', 'options' => ['symlink' => false]],
                ['type' => 'path', 'url' => './packages/consumer', 'options' => ['symlink' => false]],
            ],
            'require' => [
                'acme/consumer' => '*',
            ],
            'minimum-stability' => 'dev',
            'autoload' => ['classmap' => ['vendor']],
            'extra' => [
                'strauss' => [
                    'target_directory' => 'vendor',
                    'packages' => ['acme/lib'],
                    'namespace_prefix' => 'Strauss\\Twice\\',
                    'classmap_prefix' => 'Strauss_Twice_',
                    'functions_prefix' => 'strauss_twice_',
                    'constants_prefix' => 'STRAUSS_TWICE_',
                    'update_call_sites' => true,
                ],
            ],
        ];

        $files = [
            'composer.json' => (string) json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'packages/lib/composer.json' => (string) json_encode([
                'name' => 'acme/lib',
                'version' => '1.0.0',
                'autoload' => ['classmap' => ['src/'], 'files' => ['functions.php']],
            ]),
            'packages/lib/functions.php' => <<<'EOD'
                <?php
                if (!function_exists('acme_helper')) {
                    function acme_helper(): Global_Thing { return new Global_Thing(); }
                }
                if (!defined('ACME_VERSION')) {
                    define('ACME_VERSION', '1.0.0');
                }
                class Global_Thing {}
                EOD,
            'packages/lib/src/Widget.php' => <<<'EOD'
                <?php
                namespace Acme\Lib;
                class Widget { public function make(): \Global_Thing { return acme_helper(); } }
                EOD,
            'packages/consumer/composer.json' => (string) json_encode([
                'name' => 'acme/consumer',
                'version' => '1.0.0',
                'require' => ['acme/lib' => '*'],
                'autoload' => ['files' => ['consumer.php']],
            ]),
            'packages/consumer/consumer.php' => <<<'EOD'
                <?php
                function acme_consumer(): string {
                    $thing = acme_helper();
                    $widget = new \Acme\Lib\Widget();
                    return get_class($thing) . ' ' . get_class($widget->make()) . ' ' . ACME_VERSION;
                }
                EOD,
        ];

        foreach ($files as $relativePath => $contents) {
            $this->getFileSystem()->write($this->testsWorkingDir . '/' . $relativePath, $contents);
        }
    }

    protected function runStraussAndDumpAutoload(): void
    {
        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        exec('composer dump-autoload 2>&1', $dumpAutoloadOutput, $dumpAutoloadExitCode);
        $this->assertEquals(0, $dumpAutoloadExitCode, implode(PHP_EOL, $dumpAutoloadOutput));
    }

    /**
     * @return array<string, string> The contents of `acme/lib`'s files, by package-relative path.
     */
    protected function readLibFiles(): array
    {
        $contents = [];
        foreach (self::LIB_FILES as $relativePath) {
            $contents[$relativePath] = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor/acme/lib/' . $relativePath);
        }
        return $contents;
    }

    /**
     * Calls the consumer's function in a new process, with the project's autoloader.
     *
     * @return array{0:int, 1:string} The exit code and the output.
     */
    protected function runConsumer(): array
    {
        $phpCode = 'require "vendor/autoload.php"; echo acme_consumer();';
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($phpCode) . ' 2>&1', $output, $exitCode);
        return [$exitCode, implode(PHP_EOL, $output)];
    }

    protected function assertConsumerIsPrefixed(): void
    {
        $consumerString = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor/acme/consumer/consumer.php');
        $this->assertStringContainsString('strauss_twice_acme_helper()', $consumerString);
        $this->assertStringContainsString('new \Strauss\Twice\Acme\Lib\Widget()', $consumerString);
        $this->assertStringContainsString('STRAUSS_TWICE_ACME_VERSION', $consumerString);

        [$exitCode, $output] = $this->runConsumer();
        $this->assertEquals(0, $exitCode, $output);
        $this->assertEquals('Strauss_Twice_Global_Thing Strauss_Twice_Global_Thing 1.0.0', $output);
    }

    /**
     * A second run leaves the already prefixed global class, function and constant, and the namespace only autoloaded
     * by `classmap`, as they are.
     */
    public function test_second_run_does_not_prefix_again(): void
    {
        chdir($this->testsWorkingDir);
        $this->writeProject();

        exec('composer install 2>&1', $installOutput, $installExitCode);
        $this->assertEquals(0, $installExitCode, implode(PHP_EOL, $installOutput));

        $this->runStraussAndDumpAutoload();

        $firstRun = $this->readLibFiles();
        $this->assertStringContainsString('function strauss_twice_acme_helper(): Strauss_Twice_Global_Thing', $firstRun['functions.php']);
        $this->assertStringContainsString("define('STRAUSS_TWICE_ACME_VERSION'", $firstRun['functions.php']);
        $this->assertStringContainsString('class Strauss_Twice_Global_Thing', $firstRun['functions.php']);
        $this->assertStringContainsString('namespace Strauss\Twice\Acme\Lib;', $firstRun['src/Widget.php']);
        $this->assertConsumerIsPrefixed();

        $this->runStraussAndDumpAutoload();

        $this->assertSame($firstRun, $this->readLibFiles());
        $this->assertConsumerIsPrefixed();
    }

    /**
     * A package not being prefixed, reinstalled since the first run, uses the names from before it: they are updated
     * to the already prefixed ones.
     */
    public function test_reinstalled_package_uses_are_updated(): void
    {
        chdir($this->testsWorkingDir);
        $this->writeProject();

        exec('composer install 2>&1', $installOutput, $installExitCode);
        $this->assertEquals(0, $installExitCode, implode(PHP_EOL, $installOutput));

        $this->runStraussAndDumpAutoload();
        $firstRun = $this->readLibFiles();

        exec('composer reinstall acme/consumer 2>&1', $reinstallOutput, $reinstallExitCode);
        $this->assertEquals(0, $reinstallExitCode, implode(PHP_EOL, $reinstallOutput));
        $this->assertStringContainsString(
            'acme_helper()',
            $this->getFileSystem()->read($this->testsWorkingDir . '/vendor/acme/consumer/consumer.php')
        );

        $this->runStraussAndDumpAutoload();

        $this->assertSame($firstRun, $this->readLibFiles());
        $this->assertConsumerIsPrefixed();
    }
}
