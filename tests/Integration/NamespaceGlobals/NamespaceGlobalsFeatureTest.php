<?php

/**
 * `strauss namespace-globals` on small fixtures, one case each, then running the code.
 *
 * @see \BrianHenryIE\Strauss\Console\Commands\NamespaceGlobalsCommand
 * @see \BrianHenryIE\Strauss\Pipeline\GlobalsNamespacer
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Integration\NamespaceGlobals;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @coversNothing
 */
class NamespaceGlobalsFeatureTest extends IntegrationTestCase
{
    /**
     * PEAR-style classes under `src/Acme/`, a consumer which stays global, and files which cannot move.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $files = [
            'composer.json' => (string) json_encode(['autoload' => ['classmap' => ['src/'], 'files' => ['src/Acme/Legacy.php']]]),
            'src/Acme/Greeter.php' => <<<'EOD'
                <?php

                /**
                 * Says hello.
                 */
                interface Acme_Greeter
                {
                    public function greet(): string;
                }
                EOD,
            'src/Acme/Base.php' => <<<'EOD'
                <?php
                declare(strict_types=1);

                abstract class Acme_Base implements Acme_Greeter, Countable
                {
                    abstract protected function name(): string;

                    public function greet(): string
                    {
                        return 'hello ' . $this->name();
                    }

                    public function count(): int
                    {
                        return strlen($this->name());
                    }
                }
                EOD,
            'src/Acme/Greeting/Formal.php' => <<<'EOD'
                <?php
                class Acme_Greeting_Formal extends Acme_Base
                {
                    protected function name(): string
                    {
                        return 'sir';
                    }

                    public static function create(): self
                    {
                        return new static();
                    }

                    /**
                     * @param mixed $value
                     */
                    public function isGreeter($value): bool
                    {
                        return $value instanceof Acme_Greeter;
                    }

                    public function names(): array
                    {
                        return [Acme_Greeting_Formal::class, 'Acme_Base', get_class(new ArrayObject())];
                    }

                    public function fail(): void
                    {
                        throw new InvalidArgumentException('formal');
                    }
                }
                EOD,
            'src/Acme/Factory.php' => <<<'EOD'
                <?php
                class Acme_Factory
                {
                    public function make(string $type): Acme_Greeter
                    {
                        $class = 'Acme_Greeting_' . $type;
                        return new $class();
                    }
                }
                EOD,
            // A global function moves with the class.
            'src/Acme/Legacy.php' => <<<'EOD'
                <?php
                // Always global.
                define('ACME_LEGACY', 'legacy');
                function acme_legacy(): Acme_Legacy
                {
                    return new Acme_Legacy();
                }
                class Acme_Legacy {}
                EOD,
            // A `const` constant moves with the class.
            'src/Acme/Constants.php' => <<<'EOD'
                <?php
                const ACME_VERSION = '1.0';
                class Acme_Constants {}
                EOD,
            // Already declares a namespace: not moved.
            'src/Acme/Mixed.php' => <<<'EOD'
                <?php
                namespace Other {
                    class Thing {}
                }
                namespace {
                    class Acme_Mixed {}
                }
                EOD,
            // Only an unnamed `namespace { }` block: the block is named.
            'src/Acme/Bracketed.php' => <<<'EOD'
                <?php
                declare(strict_types=1);

                namespace {
                    class Acme_Bracketed extends Acme_Base
                    {
                        protected function name(): string
                        {
                            return 'bracketed';
                        }
                    }
                }
                EOD,
            // `List` is a reserved word: named `AcmeList`.
            'src/Acme/List.php' => <<<'EOD'
                <?php
                class Acme_List {}
                EOD,
            // Not in `--paths` or `--update-call-sites`.
            'phpstan.neon' => "parameters:\n    excludePaths:\n        - src/Acme/Greeting/Formal.php\n        - src/Acme/Legacy.php\n",
            'consumer.php' => <<<'EOD'
                <?php
                require __DIR__ . '/vendor/autoload.php';

                function consumer_greet(Acme_Greeter $greeter): string
                {
                    return $greeter->greet();
                }

                $formal = Acme_Greeting_Formal::create();
                echo consumer_greet($formal) . PHP_EOL;
                echo count($formal) . PHP_EOL;
                echo var_export($formal->isGreeter($formal), true) . PHP_EOL;
                echo var_export($formal instanceof Acme_Base, true) . PHP_EOL;
                echo implode(',', $formal->names()) . PHP_EOL;
                try {
                    $formal->fail();
                } catch (InvalidArgumentException $exception) {
                    echo get_class($exception) . ':' . $exception->getMessage() . PHP_EOL;
                }
                echo get_class(acme_legacy()) . PHP_EOL;
                echo get_class(new Acme_Mixed()) . ',' . get_class(new Acme_List()) . ',' . get_class(new Acme_Constants()) . PHP_EOL;
                echo var_export(function_exists('acme_legacy'), true) . PHP_EOL;
                echo ACME_VERSION . ',' . var_export(defined('ACME_VERSION'), true) . ',' . ACME_LEGACY . PHP_EOL;
                echo get_class(new Acme_Bracketed()) . ':' . (new Acme_Bracketed())->greet() . PHP_EOL;
                EOD,
        ];

        foreach ($files as $relativePath => $contents) {
            $this->getFileSystem()->write($this->testsWorkingDir . '/' . $relativePath, $contents);
        }

        chdir($this->testsWorkingDir);
    }

    /**
     * @param string $options More command line options.
     */
    protected function runNamespaceGlobals(string $options = ''): void
    {
        $exitCode = $this->runStrauss(
            $output,
            // Doubled backslashes: when testing the phar, the arguments go through the shell.
            'namespace-globals --namespace=Company\\\\Project --strip-prefix=Acme_ --paths=src --update-call-sites=consumer.php ' . $options
        );
        $this->assertEquals(0, $exitCode, $output);

        exec('composer dump-autoload 2>&1', $dumpOutput, $dumpExitCode);
        $this->assertEquals(0, $dumpExitCode, implode(PHP_EOL, $dumpOutput));
    }

    /**
     * @param string $relativePath The file, relative to the project.
     */
    protected function read(string $relativePath): string
    {
        return $this->getFileSystem()->read($this->testsWorkingDir . '/' . $relativePath);
    }

    public function test_files_are_namespaced(): void
    {
        $this->runNamespaceGlobals();

        $greeter = $this->read('src/Acme/Greeter.php');
        $this->assertStringContainsString("<?php\n\nnamespace Company\\Project;\n\n/**\n * Says hello.\n */\ninterface Greeter\n", $greeter);

        $base = $this->read('src/Acme/Base.php');
        // Global classes which are not moved are imported.
        $this->assertStringContainsString("declare(strict_types=1);\n\nnamespace Company\\Project;\n\nuse Countable;\n\n", $base);
        $this->assertStringContainsString('abstract class Base implements \\Company\\Project\\Greeter, Countable', $base);

        $formal = $this->read('src/Acme/Greeting/Formal.php');
        $this->assertStringContainsString("namespace Company\\Project\\Greeting;\n\nuse ArrayObject;\nuse InvalidArgumentException;\n\n", $formal);
        $this->assertStringContainsString('class Formal extends \\Company\\Project\\Base', $formal);
        $this->assertStringContainsString('public static function create(): self', $formal);
        $this->assertStringContainsString('return new static();', $formal);
        $this->assertStringContainsString('instanceof \\Company\\Project\\Greeter', $formal);
        $this->assertStringContainsString("[\\Company\\Project\\Greeting\\Formal::class, 'Company\\\\Project\\\\Base', get_class(new ArrayObject())]", $formal);
        $this->assertStringContainsString('throw new InvalidArgumentException(', $formal);
    }

    public function test_files_which_cannot_move(): void
    {
        $this->runNamespaceGlobals();

        $legacy = $this->read('src/Acme/Legacy.php');
        $this->assertStringContainsString("namespace Company\\Project;\n\n// Always global.\ndefine('ACME_LEGACY', 'legacy');\nfunction acme_legacy(): \\Company\\Project\\Legacy", $legacy);
        $this->assertStringContainsString('return new \\Company\\Project\\Legacy();', $legacy);

        $this->assertStringContainsString('echo get_class(\\Company\\Project\\acme_legacy())', $this->read('consumer.php'));
        $this->assertStringContainsString("function_exists('Company\\\\Project\\\\acme_legacy')", $this->read('consumer.php'));

        $constants = $this->read('src/Acme/Constants.php');
        $this->assertStringContainsString("namespace Company\\Project;\n\nconst ACME_VERSION = '1.0';\nclass Constants {}", $constants);

        $consumer = $this->read('consumer.php');
        $this->assertStringContainsString("echo \\Company\\Project\\ACME_VERSION . ',' . var_export(defined('Company\\\\Project\\\\ACME_VERSION'), true) . ',' . ACME_LEGACY", $consumer);
        $this->assertStringContainsString("define('ACME_LEGACY', 'legacy');", $legacy);
        $this->assertStringContainsString('class Acme_Mixed', $this->read('src/Acme/Mixed.php'));
        $this->assertStringContainsString("namespace Company\\Project;\n\nclass AcmeList", $this->read('src/Acme/List.php'));
    }

    /**
     * A file whose only namespace is an unnamed `namespace { }` block: the block is named.
     */
    public function test_bracketed_global_namespace_is_named(): void
    {
        $this->runNamespaceGlobals();

        $bracketed = $this->read('src/Acme/Bracketed.php');
        $this->assertStringContainsString("declare(strict_types=1);\n\nnamespace Company\\Project {\n    class Bracketed extends \\Company\\Project\\Base\n", $bracketed);
        $this->assertStringNotContainsString('namespace Company\\Project;', $bracketed);
    }

    public function test_code_runs(): void
    {
        $this->runNamespaceGlobals();

        exec(escapeshellarg(PHP_BINARY) . ' consumer.php 2>&1', $output, $exitCode);
        $outputString = implode(PHP_EOL, $output);

        $this->assertEquals(0, $exitCode, $outputString);
        $this->assertSame(
            implode(
                PHP_EOL,
                [
                    'hello sir',
                    '3',
                    'true',
                    'true',
                    'Company\\Project\\Greeting\\Formal,Company\\Project\\Base,ArrayObject',
                    'InvalidArgumentException:formal',
                    'Company\\Project\\Legacy',
                    'Acme_Mixed,Company\\Project\\AcmeList,Company\\Project\\Constants',
                    'true',
                    '1.0,true,legacy',
                    'Company\\Project\\Bracketed:hello bracketed',
                ]
            ),
            $outputString
        );
    }

    /**
     * `'Acme_Greeting_' . $type` builds a class name: the prefix string is rewritten to the namespace.
     */
    public function test_class_name_built_from_a_prefix_string(): void
    {
        $this->runNamespaceGlobals();

        $this->assertStringContainsString("\$class = 'Company\\\\Project\\\\Greeting\\\\' . \$type;", $this->read('src/Acme/Factory.php'));

        $this->getFileSystem()->write(
            $this->testsWorkingDir . '/factory.php',
            "<?php\nrequire __DIR__ . '/vendor/autoload.php';\necho get_class((new \\Company\\Project\\Factory())->make('Formal'));\n"
        );
        exec(escapeshellarg(PHP_BINARY) . ' factory.php 2>&1', $output, $exitCode);
        $outputString = implode(PHP_EOL, $output);

        $this->assertEquals(0, $exitCode, $outputString);
        $this->assertSame('Company\\Project\\Greeting\\Formal', $outputString);
    }

    /**
     * `--dry-run` must not change any file.
     */
    public function test_dry_run_changes_nothing(): void
    {
        $paths = ['src/Acme/Greeter.php', 'src/Acme/Base.php', 'src/Acme/Greeting/Formal.php', 'src/Acme/Legacy.php', 'consumer.php'];
        $before = array_combine($paths, array_map([$this, 'read'], $paths));

        $exitCode = $this->runStrauss(
            $output,
            // Doubled backslashes: when testing the phar, the arguments go through the shell.
            'namespace-globals --namespace=Company\\\\Project --strip-prefix=Acme_ --paths=src --update-call-sites=consumer.php --dry-run'
        );
        $this->assertEquals(0, $exitCode, $output);

        $this->assertSame($before, array_combine($paths, array_map([$this, 'read'], $paths)));
    }

    /**
     * @return string[] The PHP files in `src`, relative to the project, sorted.
     */
    protected function getSourceFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->testsWorkingDir . '/src', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $files[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($this->testsWorkingDir) + 1);
        }
        sort($files);
        return $files;
    }

    /**
     * The consumer's output is the same wherever the class files are.
     */
    protected function assertCodeRuns(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' consumer.php 2>&1', $output, $exitCode);
        $outputString = implode(PHP_EOL, $output);

        $this->assertEquals(0, $exitCode, $outputString);
        $this->assertStringContainsString('hello sir', $outputString);
        $this->assertStringContainsString('Company\\Project\\Bracketed:hello bracketed', $outputString);
    }

    /**
     * `--rename-files=psr4`: each class file is named for its class, in the directory of its namespace.
     */
    public function test_rename_files_psr4(): void
    {
        $this->runNamespaceGlobals('--rename-files=psr4');

        $this->assertSame(
            [
                // Not moved: a constant too, a function too, and a file with another namespace.
                'src/Acme/Constants.php',
                'src/Acme/Legacy.php',
                'src/Acme/Mixed.php',
                'src/AcmeList.php',
                'src/Base.php',
                'src/Bracketed.php',
                'src/Factory.php',
                'src/Greeter.php',
                'src/Greeting/Formal.php',
            ],
            $this->getSourceFiles()
        );

        $this->assertCodeRuns();
    }

    /**
     * `--rename-files=wordpress`: `class-{name}.php`, in lowercase with hyphens.
     */
    public function test_rename_files_wordpress(): void
    {
        $this->runNamespaceGlobals('--rename-files=wordpress');

        $this->assertSame(
            [
                'src/Acme/Constants.php',
                'src/Acme/Legacy.php',
                'src/Acme/Mixed.php',
                'src/class-acmelist.php',
                'src/class-base.php',
                'src/class-bracketed.php',
                'src/class-factory.php',
                'src/greeting/class-formal.php',
                'src/interface-greeter.php',
            ],
            $this->getSourceFiles()
        );

        // The project's other files which mention a renamed file are updated.
        $this->assertSame(
            "parameters:\n    excludePaths:\n        - src/greeting/class-formal.php\n        - src/Acme/Legacy.php\n",
            $this->read('phpstan.neon')
        );

        $this->assertCodeRuns();
    }

    /**
     * In a git repository, the files git tracks are renamed with `git mv`, so the renames are staged; other files are
     * only moved.
     */
    public function test_rename_files_uses_git_mv(): void
    {
        $this->getFileSystem()->write(
            $this->testsWorkingDir . '/src/Acme/Untracked.php',
            "<?php\nclass Acme_Untracked {}\n"
        );

        $git = 'git -c user.name=Test -c user.email=test@example.com -c commit.gpgsign=false ';
        exec(
            'git init -q 2>&1 && git add composer.json consumer.php phpstan.neon src/Acme/Greeter.php src/Acme/Base.php src/Acme/Greeting 2>&1 && '
            . $git . 'commit -q -m fixtures 2>&1',
            $gitOutput,
            $gitExitCode
        );
        $this->assertEquals(0, $gitExitCode, implode(PHP_EOL, $gitOutput));

        $this->runNamespaceGlobals('--rename-files=wordpress');

        exec('git status --porcelain 2>&1', $status);
        $status = implode(PHP_EOL, $status);

        // Staged renames; the contents changed too, which is not staged.
        $this->assertMatchesRegularExpression('#^R[ M] src/Acme/Greeter\.php -> src/interface-greeter\.php$#m', $status);
        $this->assertMatchesRegularExpression('#^R[ M] src/Acme/Base\.php -> src/class-base\.php$#m', $status);
        $this->assertMatchesRegularExpression('#^R[ M] src/Acme/Greeting/Formal\.php -> src/greeting/class-formal\.php$#m', $status);
        $this->assertStringNotContainsString('.moving', $status);

        // Not tracked: moved, and still not tracked.
        $this->assertFileExists($this->testsWorkingDir . '/src/class-untracked.php');
        $this->assertFileDoesNotExist($this->testsWorkingDir . '/src/Acme/Untracked.php');
        $this->assertMatchesRegularExpression('#^\?\? src/class-untracked\.php$#m', $status);

        $this->assertCodeRuns();
    }

    /**
     * `--dry-run` with `--rename-files` must not move any file.
     */
    public function test_rename_files_dry_run_changes_nothing(): void
    {
        $before = $this->getSourceFiles();

        $exitCode = $this->runStrauss(
            $output,
            'namespace-globals --namespace=Company\\\\Project --strip-prefix=Acme_ --paths=src --update-call-sites=consumer.php --rename-files=psr4 --dry-run'
        );
        $this->assertEquals(0, $exitCode, $output);

        $this->assertSame($before, $this->getSourceFiles());
        $this->assertStringContainsString('class Acme_Base', $this->read('src/Acme/Base.php'));
        $this->assertStringContainsString('src/Acme/Greeting/Formal.php', $this->read('phpstan.neon'));
    }
}
