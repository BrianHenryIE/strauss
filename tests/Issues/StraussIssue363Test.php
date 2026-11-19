<?php

/**
 * Dev aliases fatal for final classes: "cannot extend final class".
 *
 * `vendor/composer/autoload_aliases.php` aliases a deleted package's class by declaring a subclass of the prefixed
 * class under the original name. PHP cannot extend a final class, and the subclass is a different class, so objects
 * created by the prefixed code do not satisfy type declarations using the original name.
 *
 * @see src/Pipeline/Aliases/autoload_aliases.template.php
 * @see \BrianHenryIE\Strauss\Pipeline\Aliases\ClassAliasesFeatureTest for the behaviour a fix must keep.
 * @see https://github.com/BrianHenryIE/strauss/issues/363
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue363Test extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $composerJsonString = <<<'EOD'
            {
              "name": "strauss/issue363",
              "require": {
                "guzzlehttp/psr7": "^2.7"
              },
              "extra": {
                "strauss": {
                  "namespace_prefix": "Strauss\\Issue363\\",
                  "delete_vendor_packages": true
                }
              }
            }
            EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install 2>&1', $composerOutput, $composerExitCode);
        $this->assertEquals(0, $composerExitCode, implode(PHP_EOL, $composerOutput));

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);
    }

    /**
     * Runs PHP, as code which still uses the original names would, after `vendor/autoload.php`.
     *
     * @param string $php      The PHP to run, without the opening tag.
     * @param ?int   $exitCode Set to the exit code of `php`.
     *
     * @return string The output, including stderr.
     */
    protected function runDevCode(string $php, ?int &$exitCode = null): string
    {
        $filepath = $this->testsWorkingDir . '/dev-code.php';
        file_put_contents($filepath, "<?php\nrequire __DIR__ . '/vendor/autoload.php';\n" . $php);

        exec('php ' . escapeshellarg($filepath) . ' 2>&1', $output, $exitCode);

        return implode(PHP_EOL, $output);
    }

    /**
     * A non-final class works through its original name, as before.
     */
    public function test_non_final_class_by_original_name(): void
    {
        $output = $this->runDevCode(
            'echo var_export(class_exists("GuzzleHttp\\\\Psr7\\\\Request"), true);',
            $exitCode
        );

        $this->assertEquals(0, $exitCode, $output);
        $this->assertSame('true', $output);
    }

    /**
     * `Fatal error: Class GuzzleHttp\Psr7\Utils cannot extend final class Strauss\Issue363\GuzzleHttp\Psr7\Utils`.
     */
    public function test_final_class_by_original_name(): void
    {
        $output = $this->runDevCode(
            'echo var_export(class_exists("GuzzleHttp\\\\Psr7\\\\Utils"), true) . PHP_EOL;'
            . 'echo \GuzzleHttp\Psr7\Utils::caselessRemove(["a"], ["A" => 1, "b" => 2])["b"];',
            $exitCode
        );

        $this->assertEquals(0, $exitCode, $output);
        $this->assertSame('true' . PHP_EOL . '2', $output);
    }

    /**
     * An object created by the prefixed code satisfies type declarations using the original class name.
     *
     * `TypeError: original_type(): Argument #1 ($request) must be of type GuzzleHttp\Psr7\Request,
     * Strauss\Issue363\GuzzleHttp\Psr7\Request given`.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/363#issuecomment-6271068522
     */
    public function test_prefixed_object_is_instance_of_original_class(): void
    {
        $php = <<<'EOD'
            function original_type(\GuzzleHttp\Psr7\Request $request): string { return 'ok'; }
            $prefixed = new \Strauss\Issue363\GuzzleHttp\Psr7\Request('GET', '/');
            echo var_export($prefixed instanceof \GuzzleHttp\Psr7\Request, true) . PHP_EOL;
            echo original_type($prefixed);
            EOD;

        $output = $this->runDevCode($php, $exitCode);

        $this->assertEquals(0, $exitCode, $output);
        $this->assertSame('true' . PHP_EOL . 'ok', $output);
    }
}
