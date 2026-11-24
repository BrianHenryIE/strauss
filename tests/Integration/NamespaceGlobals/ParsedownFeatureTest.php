<?php

/**
 * `strauss namespace-globals` on a real, non-namespaced package, then the package's own test suite.
 *
 * Parsedown 1.8.0 is a single global class, `Parsedown`, without a prefix. Its tests stay global and extend it, refer
 * to it by name, and check `assertInstanceOf('Parsedown', ...)` with a string. Its tests are `export-ignore`d from the
 * Composer dist, so it is cloned with git.
 *
 * @see \BrianHenryIE\Strauss\Console\Commands\NamespaceGlobalsCommand
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Integration\NamespaceGlobals;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @coversNothing
 */
class ParsedownFeatureTest extends IntegrationTestCase
{
    public function test_parsedown_tests_pass_after_namespacing(): void
    {
        $projectDir = $this->testsWorkingDir . '/parsedown';

        exec(
            'git clone --quiet --depth 1 --branch 1.8.0 https://github.com/erusev/parsedown.git '
            . escapeshellarg($projectDir) . ' 2>&1',
            $cloneOutput,
            $cloneExitCode
        );
        $this->assertEquals(0, $cloneExitCode, implode(PHP_EOL, $cloneOutput));

        chdir($projectDir);

        exec('composer install --no-interaction 2>&1', $composerOutput, $composerExitCode);
        $this->assertEquals(0, $composerExitCode, implode(PHP_EOL, $composerOutput));

        $exitCode = $this->runStrauss(
            $output,
            // Doubled backslashes: when testing the phar, the arguments go through the shell.
            'namespace-globals --namespace=Company\\\\Project --paths=Parsedown.php --update-call-sites=Parsedown.php,test'
        );
        $this->assertEquals(0, $exitCode, $output);

        $parsedownPhp = $this->getFileSystem()->read($projectDir . '/Parsedown.php');
        $this->assertStringContainsString('namespace Company\\Project;', $parsedownPhp);
        $this->assertStringContainsString('class Parsedown' . PHP_EOL, $parsedownPhp);

        $sampleExtensionsPhp = $this->getFileSystem()->read($projectDir . '/test/SampleExtensions.php');
        $this->assertStringContainsString('class UnsafeExtension extends \\Company\\Project\\Parsedown', $sampleExtensionsPhp);

        $parsedownTestPhp = $this->getFileSystem()->read($projectDir . '/test/ParsedownTest.php');
        $this->assertStringContainsString("assertInstanceOf('Company\\\\Project\\\\Parsedown'", $parsedownTestPhp);

        // As the command advises: `Parsedown` is no longer found by its PSR-0 entry.
        $composerJson = json_decode($this->getFileSystem()->read($projectDir . '/composer.json'), true);
        $composerJson['autoload'] = ['classmap' => ['Parsedown.php']];
        $this->getFileSystem()->write($projectDir . '/composer.json', (string) json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        exec('composer dump-autoload 2>&1', $dumpOutput, $dumpExitCode);
        $this->assertEquals(0, $dumpExitCode, implode(PHP_EOL, $dumpOutput));

        exec(escapeshellarg(PHP_BINARY) . ' vendor/bin/phpunit 2>&1', $phpunitOutput, $phpunitExitCode);
        $phpunitOutputString = implode(PHP_EOL, $phpunitOutput);

        $this->assertEquals(0, $phpunitExitCode, $phpunitOutputString);
        $this->assertStringContainsString('OK (68 tests', $phpunitOutputString);
    }
}
