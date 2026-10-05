<?php
/**
 * Builds the command line which starts a worker: the same PHP binary and the same Strauss script as the parent,
 * so it works for `bin/strauss`, `vendor/bin/strauss` and `strauss.phar`.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline\Parallel;

use BrianHenryIE\Strauss\Console\Commands\WorkerCommand;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class WorkerProcessFactory
{
    /**
     * Set to `0` (or `false`, `off`, `no`) to start workers without the JIT compiler.
     */
    public const JIT_ENVIRONMENT_VARIABLE = 'STRAUSS_WORKER_JIT';

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

        foreach ($this->getJitOptions() as $option) {
            $command[] = '-d';
            $command[] = $option;
        }

        $command[] = $this->getMainScript();
        $command[] = WorkerCommand::NAME;

        return $command;
    }

    /**
     * The ini settings which turn on OPcache's JIT compiler in a worker. Parsing is CPU-bound PHP code, which is
     * what the JIT speeds up, and OPcache is off by default on the command line.
     *
     * None when the JIT is not available: before PHP 8.0, without OPcache, or with Xdebug loaded (the two cannot
     * run together and PHP would warn). Set the environment variable `STRAUSS_WORKER_JIT=0` to turn it off.
     *
     * @return string[] `name=value` ini settings.
     */
    public function getJitOptions(): array
    {
        $environment = getenv(self::JIT_ENVIRONMENT_VARIABLE);
        if (false !== $environment && in_array(strtolower(trim($environment)), ['0', 'false', 'off', 'no'], true)) {
            return [];
        }

        if (!$this->isJitAvailable()) {
            return [];
        }

        return [
            'opcache.enable_cli=1',
            'opcache.jit=tracing',
            'opcache.jit_buffer_size=128M',
        ];
    }

    protected function isJitAvailable(): bool
    {
        return PHP_VERSION_ID >= 80000
            && extension_loaded('Zend OPcache')
            && !extension_loaded('xdebug');
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
