<?php

/**
 * Regression tests for `vendor/composer/autoload_aliases.php`: code still using the original, unprefixed names of a
 * deleted package's classes, interfaces and traits, e.g. dev dependencies, keeps working against the prefixed copies.
 *
 * Classes are aliased by a subclass of the prefixed class which also implements the original interface names, so an
 * object created by its original name satisfies type declarations using either the original or the prefixed names.
 * These pin that behaviour, so it is kept if the aliasing changes, e.g. to `class_alias()`.
 *
 * @see \BrianHenryIE\Strauss\Pipeline\Aliases\Aliases
 * @see src/Pipeline/Aliases/autoload_aliases.template.php
 * @see https://github.com/BrianHenryIE/strauss/issues/81
 * @see https://github.com/BrianHenryIE/strauss/issues/363
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline\Aliases;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @coversNothing
 */
class ClassAliasesFeatureTest extends IntegrationTestCase
{
    /**
     * `acme/greeter`, prefixed with `Strauss\Aliases\` then deleted from `vendor` (`delete_vendor_packages`).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $composerJson = [
            'name' => 'strauss/class-aliases',
            'repositories' => [
                ['type' => 'path', 'url' => './packages/greeter', 'options' => ['symlink' => false]],
            ],
            'require' => [
                'acme/greeter' => '*',
            ],
            'minimum-stability' => 'dev',
            'extra' => [
                'strauss' => [
                    'namespace_prefix' => 'Strauss\\Aliases\\',
                    'delete_vendor_packages' => true,
                ],
            ],
        ];

        $files = [
            'composer.json' => (string) json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'packages/greeter/composer.json' => (string) json_encode([
                'name' => 'acme/greeter',
                'version' => '1.0.0',
                'autoload' => ['psr-4' => ['Acme\\Greeter\\' => 'src/']],
            ]),
            'packages/greeter/src/Contracts/Greeter.php' => <<<'EOD'
                <?php
                namespace Acme\Greeter\Contracts;
                interface Greeter { public function greet(): string; }
                EOD,
            'packages/greeter/src/Greeting.php' => <<<'EOD'
                <?php
                namespace Acme\Greeter;
                use Acme\Greeter\Contracts\Greeter;
                class Greeting implements Greeter {
                    public function greet(): string { return 'hello'; }
                    public static function create(): self { return new self(); }
                }
                EOD,
            'packages/greeter/src/AbstractGreeting.php' => <<<'EOD'
                <?php
                namespace Acme\Greeter;
                use Acme\Greeter\Contracts\Greeter;
                abstract class AbstractGreeting implements Greeter {
                    abstract protected function name(): string;
                    public function greet(): string { return 'hello ' . $this->name(); }
                }
                EOD,
            'packages/greeter/src/FinalGreeting.php' => <<<'EOD'
                <?php
                namespace Acme\Greeter;
                use Acme\Greeter\Contracts\Greeter;
                final class FinalGreeting implements Greeter {
                    public function greet(): string { return 'hello from final'; }
                }
                EOD,
            'packages/greeter/src/GreetsTrait.php' => <<<'EOD'
                <?php
                namespace Acme\Greeter;
                trait GreetsTrait { public function greet(): string { return 'hello from trait'; } }
                EOD,
            'packages/greeter/src/Printer.php' => <<<'EOD'
                <?php
                namespace Acme\Greeter;
                use Acme\Greeter\Contracts\Greeter;
                class Printer {
                    public function print(Greeter $greeter): string { return $greeter->greet(); }
                    public function greeting(): Greeting { return new Greeting(); }
                }
                EOD,
        ];

        foreach ($files as $relativePath => $contents) {
            $this->getFileSystem()->write($this->testsWorkingDir . '/' . $relativePath, $contents);
        }

        chdir($this->testsWorkingDir);

        exec('composer install 2>&1', $composerOutput, $composerExitCode);
        $this->assertEquals(0, $composerExitCode, implode(PHP_EOL, $composerOutput));

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $this->assertFileDoesNotExist($this->testsWorkingDir . '/vendor/acme/greeter/src/Greeting.php');
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

    public function test_class_by_original_name(): void
    {
        $output = $this->runDevCode('echo (new \Acme\Greeter\Greeting())->greet();', $exitCode);

        $this->assertEquals(0, $exitCode, $output);
        $this->assertSame('hello', $output);
    }

    public function test_static_method_by_original_name(): void
    {
        $output = $this->runDevCode('echo \Acme\Greeter\Greeting::create()->greet();', $exitCode);

        $this->assertEquals(0, $exitCode, $output);
        $this->assertSame('hello', $output);
    }

    /**
     * The reason classes are aliased by a subclass rather than `class_alias()`: the subclass also implements the
     * original interface names, so dev code typed against the original interface accepts it.
     */
    public function test_class_by_original_name_is_instance_of_original_interface(): void
    {
        $php = <<<'EOD'
            function original_type(\Acme\Greeter\Contracts\Greeter $greeter): string { return $greeter->greet(); }
            $greeting = new \Acme\Greeter\Greeting();
            echo var_export($greeting instanceof \Acme\Greeter\Contracts\Greeter, true) . PHP_EOL;
            echo original_type($greeting);
            EOD;

        $output = $this->runDevCode($php, $exitCode);

        $this->assertEquals(0, $exitCode, $output);
        $this->assertSame('true' . PHP_EOL . 'hello', $output);
    }

    /**
     * An object created by its original name can be passed to the prefixed code.
     */
    public function test_class_by_original_name_is_instance_of_prefixed_class_and_interface(): void
    {
        $php = <<<'EOD'
            $greeting = new \Acme\Greeter\Greeting();
            echo var_export($greeting instanceof \Strauss\Aliases\Acme\Greeter\Greeting, true) . PHP_EOL;
            echo var_export($greeting instanceof \Strauss\Aliases\Acme\Greeter\Contracts\Greeter, true) . PHP_EOL;
            echo (new \Strauss\Aliases\Acme\Greeter\Printer())->print($greeting);
            EOD;

        $output = $this->runDevCode($php, $exitCode);

        $this->assertEquals(0, $exitCode, $output);
        $this->assertSame('true' . PHP_EOL . 'true' . PHP_EOL . 'hello', $output);
    }

    /**
     * Dev code can extend an abstract class by its original name, and the subclass satisfies both the original and the
     * prefixed interface.
     */
    public function test_abstract_class_by_original_name_can_be_extended(): void
    {
        $php = <<<'EOD'
            class DevGreeting extends \Acme\Greeter\AbstractGreeting {
                protected function name(): string { return 'dev'; }
            }
            $greeting = new DevGreeting();
            echo var_export($greeting instanceof \Acme\Greeter\Contracts\Greeter, true) . PHP_EOL;
            echo (new \Strauss\Aliases\Acme\Greeter\Printer())->print($greeting);
            EOD;

        $output = $this->runDevCode($php, $exitCode);

        $this->assertEquals(0, $exitCode, $output);
        $this->assertSame('true' . PHP_EOL . 'hello dev', $output);
    }

    /**
     * Dev code implementing the original interface can be passed to prefixed code typed against the prefixed one.
     */
    public function test_dev_class_implementing_original_interface_accepted_by_prefixed_code(): void
    {
        $php = <<<'EOD'
            class DevGreeter implements \Acme\Greeter\Contracts\Greeter {
                public function greet(): string { return 'hello dev'; }
            }
            echo (new \Strauss\Aliases\Acme\Greeter\Printer())->print(new DevGreeter());
            EOD;

        $output = $this->runDevCode($php, $exitCode);

        $this->assertEquals(0, $exitCode, $output);
        $this->assertSame('hello dev', $output);
    }

    public function test_trait_by_original_name(): void
    {
        $php = <<<'EOD'
            class DevGreeter { use \Acme\Greeter\GreetsTrait; }
            echo (new DevGreeter())->greet();
            EOD;

        $output = $this->runDevCode($php, $exitCode);

        $this->assertEquals(0, $exitCode, $output);
        $this->assertSame('hello from trait', $output);
    }
}
