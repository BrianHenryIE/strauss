<?php
/**
 * One worker process and the state of the chunk it is working on.
 *
 * @internal Used by {@see WorkerPool}.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline\Parallel;

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class Worker
{
    public Process $process;

    public InputStream $input;

    /** @var array<string, true> Files sent in the current chunk, keyed by path, removed as results arrive. */
    public array $pendingFiles = [];

    /** @var ?float Null when the worker has no outstanding chunk. */
    public ?float $chunkStartedAt = null;

    /** Output received but not yet terminated by a newline. */
    public string $buffer = '';

    public function __construct(Process $process, InputStream $input)
    {
        $this->process = $process;
        $this->input = $input;
    }

    /**
     * @param array{0:string, 1?:string[]} $message `[action, files]`.
     */
    public function send(array $message): void
    {
        $this->input->write(json_encode(['action' => $message[0], 'files' => $message[1] ?? []]) . "\n");
    }

    /**
     * Complete lines received since the last call.
     *
     * @return string[]
     */
    public function readLines(): array
    {
        $this->buffer .= $this->process->getIncrementalOutput();

        $lines = [];
        while (false !== ($newline = strpos($this->buffer, "\n"))) {
            $line = trim(substr($this->buffer, 0, $newline));
            $this->buffer = substr($this->buffer, $newline + 1);
            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    public function isChunkDone(): bool
    {
        return is_null($this->chunkStartedAt) && !$this->input->isClosed();
    }

    public function isChunkTimedOut(int $timeoutSeconds): bool
    {
        return !is_null($this->chunkStartedAt) && (microtime(true) - $this->chunkStartedAt) > $timeoutSeconds;
    }
}
