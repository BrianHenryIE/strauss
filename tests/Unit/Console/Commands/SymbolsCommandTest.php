<?php

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Unit\Console\Commands;

use BrianHenryIE\Strauss\Console\Commands\SymbolsCommand;
use BrianHenryIE\Strauss\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Console\Commands\SymbolsCommand
 */
class SymbolsCommandTest extends TestCase
{
    protected string $fixturesDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestsTempDir();
        $this->fixturesDirectory = $this->testsWorkingDir;

        mkdir($this->fixturesDirectory . '/src/Sub', 0777, true);

        file_put_contents(
            $this->fixturesDirectory . '/src/Thing.php',
            <<<'PHP'
                <?php
                namespace Company\Project;

                class Thing {}
                interface ThingInterface {}
                trait ThingTrait {}
                function thing_function() {}
                const THING_CONSTANT = 1;
                PHP
        );

        file_put_contents(
            $this->fixturesDirectory . '/src/Sub/Other.php',
            <<<'PHP'
                <?php
                namespace Company\Project\Sub;

                class Other {}
                PHP
        );

        file_put_contents(
            $this->fixturesDirectory . '/src/global.php',
            <<<'PHP'
                <?php
                class Global_Class {}
                interface Global_Interface {}
                function global_function() {}
                const GLOBAL_CONSTANT = 1;
                PHP
        );

        file_put_contents($this->fixturesDirectory . '/src/readme.txt', 'namespace Not\Php;');
    }

    protected function tearDown(): void
    {
        foreach (
            [
                '/src/Thing.php',
                '/src/Sub/Other.php',
                '/src/global.php',
                '/src/readme.txt',
            ] as $file
        ) {
            @unlink($this->fixturesDirectory . $file);
        }
        @rmdir($this->fixturesDirectory . '/src/Sub');
        @rmdir($this->fixturesDirectory . '/src');
        @rmdir($this->fixturesDirectory);

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $input The command's arguments and options.
     *
     * @return array{0:int, 1:string} The exit code and the output.
     */
    protected function runCommand(array $input): array
    {
        $commandTester = new CommandTester(new SymbolsCommand());

        $exitCode = $commandTester->execute($input);

        return [$exitCode, $commandTester->getDisplay()];
    }

    /**
     * @covers ::execute
     * @covers ::getSymbolNamesByType
     */
    public function test_lists_all_symbol_types(): void
    {
        [$exitCode, $output] = $this->runCommand(['paths' => [$this->fixturesDirectory . '/src']]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $expected = [
            'namespace  Company\Project',
            'namespace  Company\Project\Sub',
            'class      Company\Project\Sub\Other',
            'class      Company\Project\Thing',
            'class      Global_Class',
            'interface  Company\Project\ThingInterface',
            'interface  Global_Interface',
            'trait      Company\Project\ThingTrait',
            'function   Company\Project\thing_function',
            'function   global_function',
            'constant   Company\Project\THING_CONSTANT',
            'constant   GLOBAL_CONSTANT',
        ];
        self::assertSame($expected, $this->lines($output));
    }

    /**
     * @covers ::getSymbolNamesByType
     */
    public function test_filter_to_global_namespace(): void
    {
        [$exitCode, $output] = $this->runCommand(
            [
                'paths' => [$this->fixturesDirectory . '/src'],
                '--global' => true,
            ]
        );

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(
            [
                'class      Global_Class',
                'interface  Global_Interface',
                'function   global_function',
                'constant   GLOBAL_CONSTANT',
            ],
            $this->lines($output)
        );
    }

    /**
     * `--global` combines with `--type`; namespaces are never global.
     *
     * @covers ::getSymbolNamesByType
     */
    public function test_filter_to_global_namespace_and_type(): void
    {
        [$exitCode, $output] = $this->runCommand(
            [
                'paths' => [$this->fixturesDirectory . '/src'],
                '--global' => true,
                '--type' => ['namespace,function'],
            ]
        );

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['function   global_function'], $this->lines($output));
    }

    /**
     * A single type prints only the names, so the output can be piped.
     *
     * @covers ::execute
     * @covers ::getTypesFromInput
     */
    public function test_filter_to_namespaces(): void
    {
        [$exitCode, $output] = $this->runCommand(
            [
                'paths' => [$this->fixturesDirectory . '/src'],
                '--type' => ['namespace'],
            ]
        );

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['Company\Project', 'Company\Project\Sub'], $this->lines($output));
    }

    /**
     * @covers ::getTypesFromInput
     */
    public function test_filter_comma_separated_and_repeated_types(): void
    {
        [$exitCode, $output] = $this->runCommand(
            [
                'paths' => [$this->fixturesDirectory . '/src'],
                '--type' => ['TRAIT,interface', 'trait'],
            ]
        );

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(
            [
                'interface  Company\Project\ThingInterface',
                'interface  Global_Interface',
                'trait      Company\Project\ThingTrait',
            ],
            $this->lines($output)
        );
    }

    /**
     * @covers ::getTypesFromInput
     */
    public function test_unknown_type_fails(): void
    {
        [$exitCode, $output] = $this->runCommand(
            [
                'paths' => [$this->fixturesDirectory . '/src'],
                '--type' => ['namespaces'],
            ]
        );

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Unknown type "namespaces"', $output);
    }

    /**
     * @covers ::getPathsFromInput
     */
    public function test_scans_multiple_paths(): void
    {
        [$exitCode, $output] = $this->runCommand(
            [
                'paths' => [
                    $this->fixturesDirectory . '/src/Sub',
                    $this->fixturesDirectory . '/src/global.php',
                ],
                '--type' => ['class'],
            ]
        );

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['Company\Project\Sub\Other', 'Global_Class'], $this->lines($output));
    }

    /**
     * @return string[]
     */
    protected function lines(string $output): array
    {
        return array_values(array_filter(array_map('rtrim', explode("\n", $output))));
    }
}
