<?php
/**
 * Hidden command run by {@see WorkerPool}: reads requests from stdin, analyses the files, writes results to stdout.
 *
 * Nothing else may write to stdout; logs go to stderr and are only shown by the parent when a worker fails.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Console\Commands;

use BrianHenryIE\Strauss\Pipeline\FileSymbol\PhpFileAnalyzer;
use BrianHenryIE\Strauss\Pipeline\Parallel\WorkerPool;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class WorkerCommand extends Command
{
    public const NAME = 'worker';

    protected function configure(): void
    {
        $this->setName(self::NAME);
        $this->setDescription('Internal command: analyses files for a parent Strauss process.');
        $this->setHidden(true);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stdin = $this->getStdin();
        $analyzer = new PhpFileAnalyzer(new NullLogger());

        try {
            while (false !== ($line = fgets($stdin))) {
                $line = trim($line);
                if ('' === $line) {
                    continue;
                }

                $request = json_decode($line, true);
                if (!is_array($request) || !isset($request['action']) || !is_string($request['action'])) {
                    throw new \RuntimeException('Unexpected request: ' . $line);
                }

                if (WorkerPool::ACTION_QUIT === $request['action']) {
                    break;
                }

                if (WorkerPool::ACTION_ANALYZE !== $request['action']) {
                    throw new \RuntimeException('Unexpected action: ' . $request['action']);
                }

                $paths = $request['files'] ?? [];
                if (!is_array($paths)) {
                    throw new \RuntimeException('Unexpected request: ' . $line);
                }

                foreach ($paths as $path) {
                    if (!is_string($path)) {
                        throw new \RuntimeException('Unexpected request: ' . $line);
                    }
                    $this->write($this->analyzeFile($analyzer, $path));
                }

                $this->write(['action' => WorkerPool::ACTION_CHUNK_DONE]);
            }
        } catch (Throwable $e) {
            $this->write([
                'action' => WorkerPool::ACTION_ERROR,
                'message' => sprintf('%s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()),
            ]);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{action:string, file:string, map:?string, error:?string}
     */
    protected function analyzeFile(PhpFileAnalyzer $analyzer, string $path): array
    {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            return [
                'action' => WorkerPool::ACTION_RESULT,
                'file' => $path,
                'map' => null,
                'error' => 'Could not read file.',
            ];
        }

        $codeMap = $analyzer->analyze($contents, $path);

        return [
            'action' => WorkerPool::ACTION_RESULT,
            'file' => $path,
            'map' => is_null($codeMap) ? null : WorkerPool::encodeCodeMap($codeMap),
            'error' => is_null($codeMap) ? ($analyzer->getLastErrorMessage() ?? 'Could not parse file.') : null,
        ];
    }

    /**
     * @param array<string, mixed> $message
     */
    protected function write(array $message): void
    {
        $json = json_encode($message, JSON_INVALID_UTF8_SUBSTITUTE);
        if (false === $json) {
            throw new \RuntimeException('Could not encode message: ' . json_last_error_msg());
        }
        fwrite(STDOUT, $json . "\n");
        fflush(STDOUT);
    }

    /**
     * @return resource
     */
    protected function getStdin()
    {
        return STDIN;
    }
}
