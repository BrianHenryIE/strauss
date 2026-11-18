<?php

/**
 * In-place mode: `composer update` leaves new call sites unprefixed when the prefixed package itself is not reinstalled.
 *
 * With `target_directory: vendor`, Strauss works out which namespaces to rename by reading the files of the configured
 * packages. A `composer update` only reinstalls the packages that changed. When the prefixed package is not one of
 * them, its files already declare `Prefix\Psr\Log`, so Strauss finds no `Psr\Log` namespace to rename, and the newly
 * installed packages which use it keep their unprefixed references: a fatal error at runtime.
 *
 * `update_call_sites: true` with `vendor` in the project's `autoload.classmap` is what makes Strauss update the other
 * packages in `vendor` at all.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/343
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue343Test extends IntegrationTestCase
{
    /**
     * A reference to `Psr\Log\...` not preceded by another namespace part, i.e. not `Prefix\Psr\Log\...`.
     *
     * The issue's `grep -rlE '(^|[^\\A-Za-z])Psr\\Log\\' vendor/monolog --include='*.php'`.
     */
    protected const UNPREFIXED_REFERENCE_PATTERN = '/(^|[^\\\\A-Za-z])Psr\\\\Log\\\\/m';

    protected function getComposerJsonString(string $monologVersion): string
    {
        $composerJsonString = <<<'EOD'
            {
                "name": "example/strauss-repro",
                "require": {
                    "psr/log": "3.0.2",
                    "monolog/monolog": "MONOLOG_VERSION"
                },
                "config": { "classmap-authoritative": true },
                "autoload": { "classmap": ["vendor"] },
                "extra": {
                    "strauss": {
                        "target_directory": "vendor",
                        "namespace_prefix": "Prefix\\",
                        "packages": ["psr/log"],
                        "update_call_sites": true
                    }
                }
            }
            EOD;

        return str_replace('MONOLOG_VERSION', $monologVersion, $composerJsonString);
    }

    /**
     * Run Strauss then `composer dump-autoload`, as the issue's `post-install-cmd` and `post-update-cmd` scripts do.
     */
    protected function runStraussAndDumpAutoload(): void
    {
        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        exec('composer dump-autoload 2>&1', $dumpAutoloadOutput, $dumpAutoloadExitCode);
        $this->assertEquals(0, $dumpAutoloadExitCode, implode(PHP_EOL, $dumpAutoloadOutput));
    }

    /**
     * @param string $directory Absolute path of the directory to search, recursively.
     *
     * @return string[] Paths, relative to the tests working directory, of the PHP files with an unprefixed reference.
     */
    protected function findFilesWithUnprefixedReferences(string $directory): array
    {
        $filesWithUnprefixedReferences = [];

        /**
         * @var SplFileInfo $fileInfo
         */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $fileInfo) {
            if (!$fileInfo->isFile() || 'php' !== $fileInfo->getExtension()) {
                continue;
            }

            $contents = (string) file_get_contents($fileInfo->getPathname());

            if (1 === preg_match(self::UNPREFIXED_REFERENCE_PATTERN, $contents)) {
                $filesWithUnprefixedReferences[] = str_replace(
                    $this->testsWorkingDir . '/',
                    '',
                    str_replace('\\', '/', $fileInfo->getPathname())
                );
            }
        }

        sort($filesWithUnprefixedReferences);

        return $filesWithUnprefixedReferences;
    }

    /**
     * `php -r 'require "vendor/autoload.php"; new Monolog\Logger("x");'`
     *
     * @return array{0:int, 1:string} The exit code and the output.
     */
    protected function instantiateMonologLogger(): array
    {
        $phpCode = 'require "vendor/autoload.php"; new Monolog\Logger("x"); echo "OK";';

        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($phpCode) . ' 2>&1', $output, $exitCode);

        return [$exitCode, implode(PHP_EOL, $output)];
    }

    public function test_343(): void
    {
        $this->markTestSkippedOnPhpVersionBelow('8.1');

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $this->getComposerJsonString('3.9.0'));

        exec('composer install 2>&1', $installOutput, $installExitCode);
        $this->assertEquals(0, $installExitCode, implode(PHP_EOL, $installOutput));

        $this->runStraussAndDumpAutoload();

        // The first install is prefixed correctly.

        $loggerInterfaceString = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor/psr/log/src/LoggerInterface.php');
        $this->assertStringContainsString('namespace Prefix\Psr\Log;', $loggerInterfaceString);

        $this->assertEmpty(
            $this->findFilesWithUnprefixedReferences($this->testsWorkingDir . '/vendor/monolog'),
            'Unprefixed `Psr\Log\` references after `composer install`.'
        );

        [$exitCode, $output] = $this->instantiateMonologLogger();
        $this->assertEquals(0, $exitCode, $output);

        // Update monolog only: psr/log is not reinstalled, so its files are already prefixed.

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $this->getComposerJsonString('3.10.0'));

        exec('composer update monolog/monolog 2>&1', $updateOutput, $updateExitCode);
        $this->assertEquals(0, $updateExitCode, implode(PHP_EOL, $updateOutput));

        $this->runStraussAndDumpAutoload();

        $filesWithUnprefixedReferences = $this->findFilesWithUnprefixedReferences($this->testsWorkingDir . '/vendor/monolog');
        $this->assertEmpty(
            $filesWithUnprefixedReferences,
            sprintf(
                "Unprefixed `Psr\\Log\\` references after `composer update monolog/monolog` in %d files:\n%s",
                count($filesWithUnprefixedReferences),
                implode(PHP_EOL, $filesWithUnprefixedReferences)
            )
        );

        // Error: Interface "Psr\Log\LoggerInterface" not found
        [$exitCode, $output] = $this->instantiateMonologLogger();
        $this->assertEquals(0, $exitCode, $output);
        $this->assertEquals('OK', $output);
    }
}
