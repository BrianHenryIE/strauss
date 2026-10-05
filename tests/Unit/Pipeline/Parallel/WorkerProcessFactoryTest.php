<?php

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Unit\Pipeline\Parallel;

use BrianHenryIE\Strauss\Console\Commands\WorkerCommand;
use BrianHenryIE\Strauss\Pipeline\Parallel\WorkerProcessFactory;
use BrianHenryIE\Strauss\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\Parallel\WorkerProcessFactory
 */
class WorkerProcessFactoryTest extends TestCase
{
    /**
     * A factory which believes the JIT is, or is not, available, whatever this PHP has.
     */
    protected function getSut(bool $isJitAvailable): WorkerProcessFactory
    {
        return new class ($isJitAvailable) extends WorkerProcessFactory {
            protected bool $isJitAvailable;

            public function __construct(bool $isJitAvailable)
            {
                $this->isJitAvailable = $isJitAvailable;
            }

            protected function isJitAvailable(): bool
            {
                return $this->isJitAvailable;
            }
        };
    }

    /**
     * Run `$test` with the environment variable which turns the JIT off set to `$value`, or unset when null.
     */
    protected function withJitEnvironmentVariable(?string $value, callable $test): void
    {
        $name = WorkerProcessFactory::JIT_ENVIRONMENT_VARIABLE;
        $previous = getenv($name);

        putenv(is_null($value) ? $name : $name . '=' . $value);
        try {
            $test();
        } finally {
            putenv(false === $previous ? $name : $name . '=' . $previous);
        }
    }

    /**
     * The same PHP binary and memory limit as this process, running `bin/strauss worker`.
     *
     * @covers ::getCommand
     * @covers ::getMainScript
     */
    public function test_command_runs_the_worker_command_with_this_php(): void
    {
        $this->withJitEnvironmentVariable(null, function (): void {
            $command = $this->getSut(false)->getCommand();

            self::assertSame((new PhpExecutableFinder())->find(false), $command[0]);
            self::assertSame(
                ['-d', 'memory_limit=' . ini_get('memory_limit')],
                array_slice($command, 1, 2)
            );
            self::assertSame(
                [realpath(dirname(__DIR__, 4) . '/bin/strauss'), WorkerCommand::NAME],
                array_slice($command, -2)
            );
            self::assertCount(5, $command);
        });
    }

    /**
     * @covers ::getCommand
     * @covers ::getJitOptions
     */
    public function test_jit_is_enabled_in_workers_when_available(): void
    {
        $this->withJitEnvironmentVariable(null, function (): void {
            $sut = $this->getSut(true);

            self::assertSame(
                ['opcache.enable_cli=1', 'opcache.jit=tracing', 'opcache.jit_buffer_size=128M'],
                $sut->getJitOptions()
            );

            $command = $sut->getCommand();

            // PHP's own options come before the script.
            self::assertSame(
                [
                    '-d', 'opcache.enable_cli=1',
                    '-d', 'opcache.jit=tracing',
                    '-d', 'opcache.jit_buffer_size=128M',
                    realpath(dirname(__DIR__, 4) . '/bin/strauss'),
                    WorkerCommand::NAME,
                ],
                array_slice($command, -8)
            );
            self::assertCount(11, $command);
        });
    }

    /**
     * Before PHP 8.0, without OPcache, or with Xdebug loaded.
     *
     * @covers ::getJitOptions
     */
    public function test_no_jit_options_when_not_available(): void
    {
        $this->withJitEnvironmentVariable(null, function (): void {
            self::assertSame([], $this->getSut(false)->getJitOptions());
        });
    }

    /**
     * @return array<string, array{0:string, 1:bool}> The variable's value, and whether the JIT is still on.
     */
    public static function jitEnvironmentVariableProvider(): array
    {
        return [
            'zero' => ['0', false],
            'false' => ['false', false],
            'off' => ['OFF', false],
            'no' => [' no ', false],
            'one' => ['1', true],
            'true' => ['true', true],
            'empty' => ['', true],
        ];
    }

    /**
     * @dataProvider jitEnvironmentVariableProvider
     *
     * @covers ::getJitOptions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('jitEnvironmentVariableProvider')]
    public function test_environment_variable_turns_the_jit_off(string $value, bool $expectJit): void
    {
        $this->withJitEnvironmentVariable($value, function () use ($expectJit): void {
            self::assertSame($expectJit, [] !== $this->getSut(true)->getJitOptions());
            self::assertSame([], $this->getSut(false)->getJitOptions());
        });
    }

    /**
     * What this PHP actually supports: the JIT needs PHP 8, OPcache, and no Xdebug.
     *
     * @covers ::isJitAvailable
     * @covers ::getJitOptions
     */
    public function test_jit_availability_is_detected(): void
    {
        $expected = PHP_VERSION_ID >= 80000 && extension_loaded('Zend OPcache') && !extension_loaded('xdebug');

        $this->withJitEnvironmentVariable(null, function () use ($expected): void {
            self::assertSame($expected, [] !== (new WorkerProcessFactory())->getJitOptions());
        });
    }

    /**
     * The pool enforces chunk timeouts; the process itself must not time out while idle.
     *
     * @covers ::create
     */
    public function test_process_has_no_timeout_and_is_not_started(): void
    {
        $process = (new WorkerProcessFactory())->create();

        self::assertNull($process->getTimeout());
        self::assertFalse($process->isStarted());
        self::assertStringContainsString(WorkerCommand::NAME, $process->getCommandLine());
        self::assertStringContainsString('strauss', $process->getCommandLine());
    }
}
