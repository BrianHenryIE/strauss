<?php

/**
 * Builds the command line which starts a worker: the same PHP binary and the same Strauss script as the parent,
 * so it works for `bin/strauss`, `vendor/bin/strauss` and `strauss.phar`.
 *
 * @package brianhenryie/strauss
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline\Parallel;

use BrianHenryIE\Strauss\Console\Commands\WorkerCommand;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Finds the PHP binary with {@see PhpExecutableFinder}, passes on the parent's `memory_limit`, and resolves the main
 * script from the running phar, then `bin/strauss` relative to this file, then `argv[0]`. The resulting
 * {@see Process} runs {@see WorkerCommand} with no timeout, since chunk timeouts are enforced by {@see WorkerPool}.
 */
class WorkerProcessFactory
{
    /**
     * @return string[] The command, as {@see Process} takes it.
     */
    public function getCommand(): array
    {
        $phpBinary = (new PhpExecutableFinder())->find(false);
        if (false === $phpBinary) {
            throw new ParallelisationException('Cannot find the PHP executable to start worker processes.');
        }

        $command = [$phpBinary];

        // Workers parse files; give them the parent's memory limit rather than php.ini's default.
        $memoryLimit = (string) ini_get('memory_limit');
        if ('' !== $memoryLimit) {
            $command[] = '-d';
            $command[] = 'memory_limit=' . $memoryLimit;
        }

        $command[] = $this->getMainScript();
        $command[] = WorkerCommand::NAME;

        return $command;
    }

    public function create(): Process
    {
        $process = new Process($this->getCommand());
        // Chunk timeouts are enforced by the pool; a worker may legitimately idle while others finish.
        $process->setTimeout(null);
        return $process;
    }

    protected function getMainScript(): string
    {
        $pharPath = \Phar::running(false);
        if ('' !== $pharPath) {
            return $pharPath;
        }

        $mainScript = realpath(__DIR__ . '/../../../bin/strauss');
        if (false !== $mainScript) {
            return $mainScript;
        }

        $argv = $_SERVER['argv'] ?? null;
        if (is_array($argv) && isset($argv[0]) && is_string($argv[0]) && is_file($argv[0])) {
            return $argv[0];
        }

        throw new ParallelisationException('Cannot determine the Strauss script to start worker processes with.');
    }
}
