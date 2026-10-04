<?php

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
     * The same PHP binary and memory limit as this process, running `bin/strauss worker`.
     *
     * @covers ::getCommand
     * @covers ::getMainScript
     */
    public function test_command_runs_the_worker_command_with_this_php(): void
    {
        $command = (new WorkerProcessFactory())->getCommand();

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
