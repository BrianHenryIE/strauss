<?php
/**
 * How many worker processes to run, and how much work to hand each one at a time.
 *
 * Modelled on PHP-CS-Fixer's `ParallelConfig`: workers pull chunks of files, so a fast worker takes more of them,
 * and a chunk that takes longer than the timeout is treated as a hung worker.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline\Parallel;

use InvalidArgumentException;

class ParallelConfig
{
    public const DEFAULT_FILES_PER_CHUNK = 20;

    public const DEFAULT_CHUNK_TIMEOUT_SECONDS = 120;

    protected int $maxProcesses;

    protected int $filesPerChunk;

    protected int $chunkTimeoutSeconds;

    public function __construct(
        int $maxProcesses,
        int $filesPerChunk = self::DEFAULT_FILES_PER_CHUNK,
        int $chunkTimeoutSeconds = self::DEFAULT_CHUNK_TIMEOUT_SECONDS
    ) {
        if ($maxProcesses < 1 || $filesPerChunk < 1 || $chunkTimeoutSeconds < 1) {
            throw new InvalidArgumentException('Parallel configuration values must be positive integers.');
        }
        $this->maxProcesses = $maxProcesses;
        $this->filesPerChunk = $filesPerChunk;
        $this->chunkTimeoutSeconds = $chunkTimeoutSeconds;
    }

    public static function sequential(): self
    {
        return new self(1);
    }

    /**
     * One worker per CPU, leaving one for the parent process.
     *
     * @param ?int $maxProcesses An upper limit, e.g. from the `--parallel=N` option.
     */
    public static function detect(?int $maxProcesses = null): self
    {
        $available = max(1, self::countCpus() - 1);

        if (!is_null($maxProcesses)) {
            $available = max(1, min($available, $maxProcesses));
        }

        return new self($available);
    }

    public static function countCpus(): int
    {
        if (isset($_SERVER['NUMBER_OF_PROCESSORS']) && is_numeric($_SERVER['NUMBER_OF_PROCESSORS'])) {
            return max(1, (int) $_SERVER['NUMBER_OF_PROCESSORS']);
        }

        if (is_readable('/proc/cpuinfo')) {
            $count = preg_match_all('/^processor\s*:/m', (string) file_get_contents('/proc/cpuinfo'));
            if ($count > 0) {
                return $count;
            }
        }

        if (function_exists('shell_exec') && 'Darwin' === PHP_OS_FAMILY) {
            $count = (int) trim((string) @shell_exec('sysctl -n hw.ncpu 2>/dev/null'));
            if ($count > 0) {
                return $count;
            }
        }

        return 1;
    }

    public function isSequential(): bool
    {
        return 1 === $this->maxProcesses;
    }

    public function getMaxProcesses(): int
    {
        return $this->maxProcesses;
    }

    public function getFilesPerChunk(): int
    {
        return $this->filesPerChunk;
    }

    public function getChunkTimeoutSeconds(): int
    {
        return $this->chunkTimeoutSeconds;
    }

    /**
     * How many workers are worth starting for this many files: never more than there are chunks.
     */
    public function getProcessesToSpawn(int $fileCount): int
    {
        return max(1, min($this->maxProcesses, (int) ceil($fileCount / $this->filesPerChunk)));
    }
}
