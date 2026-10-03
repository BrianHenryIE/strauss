<?php
/**
 * Runs {@see \BrianHenryIE\Strauss\Console\Commands\WorkerCommand} processes and hands them chunks of files to
 * analyse.
 *
 * Pull-based, as in PHP-CS-Fixer's `Runner::fixParallel()`: each worker is given a chunk, reports one result per
 * file and then a "chunk done" message, at which point it gets the next chunk, so a fast worker takes more of the
 * work. Communication is newline-delimited JSON over the worker's stdin/stdout; a serialized {@see FileCodeMap} is
 * sent back base64-encoded.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline\Parallel;

use BrianHenryIE\Strauss\Files\FileCodeMap;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class WorkerPool
{
    use LoggerAwareTrait;

    public const ACTION_ANALYZE = 'analyze';
    public const ACTION_QUIT = 'quit';
    public const ACTION_RESULT = 'result';
    public const ACTION_CHUNK_DONE = 'chunk-done';
    public const ACTION_ERROR = 'error';

    protected ParallelConfig $config;

    protected WorkerProcessFactory $processFactory;

    public function __construct(
        ParallelConfig $config,
        ?WorkerProcessFactory $processFactory = null,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->processFactory = $processFactory ?? new WorkerProcessFactory();
        $this->logger = $logger ?? new NullLogger();
    }

    public function getConfig(): ParallelConfig
    {
        return $this->config;
    }

    /**
     * Analyse the files, in worker processes, calling `$onResult` for each as its result arrives.
     *
     * Results arrive in no particular order.
     *
     * @param string[] $absolutePaths
     * @param callable(string $absolutePath, ?FileCodeMap $codeMap, ?string $errorMessage): void $onResult
     *
     * @throws ParallelisationException When a worker fails, times out, or sends something unexpected.
     */
    public function analyze(array $absolutePaths, callable $onResult): void
    {
        $queue = array_values($absolutePaths);
        if (empty($queue)) {
            return;
        }

        $processesToSpawn = $this->config->getProcessesToSpawn(count($queue));

        $this->logger->debug('Analysing {fileCount} files with {workerCount} worker processes.', [
            'fileCount' => count($queue),
            'workerCount' => $processesToSpawn,
        ]);

        /** @var Worker[] $workers */
        $workers = [];
        for ($i = 0; $i < $processesToSpawn; $i++) {
            $workers[] = $this->startWorker();
        }

        try {
            foreach ($workers as $worker) {
                $this->sendNextChunk($worker, $queue);
            }

            while (!empty($workers)) {
                foreach ($workers as $index => $worker) {
                    $this->readResults($worker, $onResult);

                    if ($worker->isChunkDone()) {
                        $this->sendNextChunk($worker, $queue);
                    }

                    if (!$worker->process->isRunning()) {
                        // Output which arrived between the read above and the process ending.
                        $this->readResults($worker, $onResult);
                        $this->checkExited($worker);
                        unset($workers[$index]);
                        continue;
                    }

                    if ($worker->isChunkTimedOut($this->config->getChunkTimeoutSeconds())) {
                        throw new ParallelisationException(sprintf(
                            'Worker process timed out after %d seconds analysing: %s',
                            $this->config->getChunkTimeoutSeconds(),
                            implode(', ', $worker->pendingFiles)
                        ));
                    }
                }

                if (!empty($workers)) {
                    usleep(1000);
                }
            }
        } finally {
            foreach ($workers as $worker) {
                $worker->process->stop(0);
            }
        }
    }

    protected function startWorker(): Worker
    {
        $process = $this->processFactory->create();
        $input = new InputStream();
        $process->setInput($input);

        try {
            $process->start();
        } catch (\Throwable $e) {
            throw new ParallelisationException('Could not start a worker process: ' . $e->getMessage(), 0, $e);
        }

        return new Worker($process, $input);
    }

    /**
     * @param string[] $queue
     */
    protected function sendNextChunk(Worker $worker, array &$queue): void
    {
        if (empty($queue)) {
            $worker->send([self::ACTION_QUIT]);
            $worker->input->close();
            $worker->pendingFiles = [];
            $worker->chunkStartedAt = null;
            return;
        }

        $chunk = array_splice($queue, 0, $this->config->getFilesPerChunk());

        $worker->pendingFiles = array_fill_keys($chunk, true);
        $worker->chunkStartedAt = microtime(true);
        $worker->send([self::ACTION_ANALYZE, $chunk]);
    }

    /**
     * @param callable(string, ?FileCodeMap, ?string): void $onResult
     */
    protected function readResults(Worker $worker, callable $onResult): void
    {
        foreach ($worker->readLines() as $line) {
            $message = json_decode($line, true);
            if (!is_array($message) || !isset($message['action'])) {
                throw new ParallelisationException('Unexpected output from worker process: ' . $line);
            }

            switch ($message['action']) {
                case self::ACTION_RESULT:
                    $file = $message['file'];
                    if (!isset($worker->pendingFiles[$file])) {
                        throw new ParallelisationException('Worker process returned a result for a file it was not given: ' . $file);
                    }
                    unset($worker->pendingFiles[$file]);
                    $codeMap = isset($message['map']) ? self::decodeCodeMap($message['map']) : null;
                    $onResult($file, $codeMap, $message['error'] ?? null);
                    break;

                case self::ACTION_CHUNK_DONE:
                    if (!empty($worker->pendingFiles)) {
                        throw new ParallelisationException('Worker process finished a chunk without returning: ' . implode(', ', array_keys($worker->pendingFiles)));
                    }
                    $worker->chunkStartedAt = null;
                    break;

                case self::ACTION_ERROR:
                    throw new ParallelisationException('Worker process failed: ' . ($message['message'] ?? 'unknown error'));

                default:
                    throw new ParallelisationException('Unexpected message from worker process: ' . $line);
            }
        }
    }

    protected function checkExited(Worker $worker): void
    {
        if (0 !== $worker->process->getExitCode() || !empty($worker->pendingFiles)) {
            throw new ParallelisationException(sprintf(
                'Worker process exited with code %s%s. %s',
                (string) $worker->process->getExitCode(),
                empty($worker->pendingFiles) ? '' : ' before analysing ' . implode(', ', array_keys($worker->pendingFiles)),
                trim($worker->process->getErrorOutput() . "\n" . $worker->buffer)
            ));
        }
    }

    public static function encodeCodeMap(FileCodeMap $codeMap): string
    {
        return base64_encode(serialize($codeMap));
    }

    public static function decodeCodeMap(string $encoded): FileCodeMap
    {
        $codeMap = unserialize((string) base64_decode($encoded, true), ['allowed_classes' => true]);
        if (!($codeMap instanceof FileCodeMap)) {
            throw new ParallelisationException('Worker process returned something that is not a code map.');
        }
        return $codeMap;
    }
}
