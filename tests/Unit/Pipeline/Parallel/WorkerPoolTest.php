<?php

namespace BrianHenryIE\Strauss\Tests\Unit\Pipeline\Parallel;

use BrianHenryIE\Strauss\Files\FileCodeMap;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\PhpFileAnalyzer;
use BrianHenryIE\Strauss\Pipeline\Parallel\ParallelConfig;
use BrianHenryIE\Strauss\Pipeline\Parallel\ParallelisationException;
use BrianHenryIE\Strauss\Pipeline\Parallel\WorkerPool;
use BrianHenryIE\Strauss\Pipeline\Parallel\WorkerProcessFactory;
use BrianHenryIE\Strauss\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Starts real worker processes (`bin/strauss worker`).
 *
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\Parallel\WorkerPool
 */
class WorkerPoolTest extends TestCase
{
    /**
     * @return string[] Absolute paths of some of Strauss's own source files.
     */
    protected function getSourceFiles(int $count): array
    {
        $files = array_merge(
            glob(dirname(__DIR__, 4) . '/src/Pipeline/*.php') ?: [],
            glob(dirname(__DIR__, 4) . '/src/Types/*.php') ?: []
        );
        sort($files);
        self::assertGreaterThanOrEqual($count, count($files));
        return array_slice($files, 0, $count);
    }

    /**
     * Workers return the same map as analysing in this process does.
     *
     * @covers ::analyze
     * @covers \BrianHenryIE\Strauss\Console\Commands\WorkerCommand
     */
    public function test_results_match_sequential_analysis(): void
    {
        $files = $this->getSourceFiles(25);

        // Three workers with small chunks, so every worker handles several chunks.
        $pool = new WorkerPool(new ParallelConfig(3, 4), null, $this->getLogger());

        $results = [];
        $pool->analyze($files, function (string $path, ?FileCodeMap $codeMap, ?string $error) use (&$results): void {
            $results[$path] = [$codeMap, $error];
        });

        self::assertSame(count($files), count($results));

        $analyzer = new PhpFileAnalyzer();
        foreach ($files as $path) {
            self::assertArrayHasKey($path, $results);
            [$codeMap, $error] = $results[$path];
            self::assertNull($error, $path);
            self::assertInstanceOf(FileCodeMap::class, $codeMap);

            $expected = $analyzer->analyze((string) file_get_contents($path), $path);
            self::assertEquals($expected, $codeMap, "Map from worker differs for $path");
        }
    }

    /**
     * Unparsable and missing files are reported per file, not as a worker failure.
     *
     * @covers ::analyze
     */
    public function test_unparsable_and_missing_files_are_reported(): void
    {
        $unparsable = tempnam(sys_get_temp_dir(), 'strauss-unparsable') . '.php';
        file_put_contents($unparsable, "<?php\nnamespace %g_namespace%\\AdminMenus;\n");
        $missing = sys_get_temp_dir() . '/strauss-does-not-exist-' . uniqid() . '.php';
        $files = array_merge($this->getSourceFiles(2), [$unparsable, $missing]);

        $pool = new WorkerPool(new ParallelConfig(2, 2));

        $results = [];
        try {
            $pool->analyze($files, function (string $path, ?FileCodeMap $codeMap, ?string $error) use (&$results): void {
                $results[$path] = [$codeMap, $error];
            });
        } finally {
            unlink($unparsable);
        }

        self::assertNull($results[$unparsable][0]);
        self::assertStringContainsString('Syntax error', (string) $results[$unparsable][1]);
        self::assertNull($results[$missing][0]);
        self::assertSame('Could not read file.', $results[$missing][1]);
        self::assertInstanceOf(FileCodeMap::class, $results[$files[0]][0]);
    }

    /**
     * @covers ::analyze
     */
    public function test_nothing_to_do(): void
    {
        $pool = new WorkerPool(new ParallelConfig(2));
        $pool->analyze([], function (): void {
            self::fail('No results expected.');
        });
        self::assertTrue(true);
    }

    protected function getFactoryRunning(string $phpCode): WorkerProcessFactory
    {
        return new class ($phpCode) extends WorkerProcessFactory {
            protected string $phpCode;

            public function __construct(string $phpCode)
            {
                $this->phpCode = $phpCode;
            }

            public function create(): Process
            {
                $php = (new PhpExecutableFinder())->find(false);
                $process = new Process([$php, '-r', $this->phpCode]);
                $process->setTimeout(null);
                return $process;
            }
        };
    }

    /**
     * A worker which exits without returning its results is an error, with its stderr in the message.
     *
     * @covers ::analyze
     */
    public function test_worker_exiting_early_throws(): void
    {
        $pool = new WorkerPool(
            new ParallelConfig(1),
            $this->getFactoryRunning('fwrite(STDERR, "worker went wrong"); exit(3);')
        );

        $this->expectException(ParallelisationException::class);
        $this->expectExceptionMessage('worker went wrong');

        $pool->analyze($this->getSourceFiles(1), function (): void {
        });
    }

    /**
     * @covers ::analyze
     */
    public function test_worker_timing_out_throws(): void
    {
        $pool = new WorkerPool(
            new ParallelConfig(1, 20, 1),
            $this->getFactoryRunning('fgets(STDIN); sleep(10);')
        );

        $this->expectException(ParallelisationException::class);
        $this->expectExceptionMessage('timed out');

        $pool->analyze($this->getSourceFiles(1), function (): void {
        });
    }

    /**
     * @covers ::analyze
     */
    public function test_unexpected_output_throws(): void
    {
        $pool = new WorkerPool(
            new ParallelConfig(1),
            $this->getFactoryRunning('fgets(STDIN); echo "not json\n"; sleep(5);')
        );

        $this->expectException(ParallelisationException::class);
        $this->expectExceptionMessage('Unexpected output');

        $pool->analyze($this->getSourceFiles(1), function (): void {
        });
    }

    /**
     * The message names the files the worker was given, so a hang can be traced to a file.
     *
     * @covers ::analyze
     */
    public function test_timeout_message_lists_the_pending_files(): void
    {
        $files = $this->getSourceFiles(2);

        $pool = new WorkerPool(
            new ParallelConfig(1, 20, 1),
            $this->getFactoryRunning('fgets(STDIN); sleep(10);')
        );

        $this->expectException(ParallelisationException::class);
        $this->expectExceptionMessage('analysing: ' . implode(', ', $files));

        $pool->analyze($files, function (): void {
        });
    }

    /**
     * @return array<string, array{0:string, 1:string}> PHP code for a fake worker, and the expected message.
     */
    public static function misbehavingWorkerProvider(): array
    {
        return [
            'error message' => [
                'fgets(STDIN); echo json_encode(["action" => "error", "message" => "boom"]), "\n"; sleep(5);',
                'Worker process failed: boom',
            ],
            'error without a message' => [
                'fgets(STDIN); echo json_encode(["action" => "error"]), "\n"; sleep(5);',
                'Worker process failed: unknown error',
            ],
            'unknown action' => [
                'fgets(STDIN); echo json_encode(["action" => "dance"]), "\n"; sleep(5);',
                'Unexpected message from worker process: {"action":"dance"}',
            ],
            'json without an action' => [
                'fgets(STDIN); echo json_encode(["file" => "a.php"]), "\n"; sleep(5);',
                'Unexpected output from worker process',
            ],
            'result for a file it was not given' => [
                'fgets(STDIN); echo json_encode(["action" => "result", "file" => "/not/asked/for.php"]), "\n"; sleep(5);',
                'a file it was not given: /not/asked/for.php',
            ],
            'chunk done without results' => [
                'fgets(STDIN); echo json_encode(["action" => "chunk-done"]), "\n"; sleep(5);',
                'finished a chunk without returning: ',
            ],
            'map which is not a code map' => [
                '$request = json_decode(fgets(STDIN), true);'
                . ' echo json_encode(["action" => "result", "file" => $request["files"][0], "map" => base64_encode(serialize(["not a map"]))]), "\n";'
                . ' sleep(5);',
                'not a code map',
            ],
            'exits cleanly before analysing' => [
                'exit(0);',
                'Worker process exited with code 0 before analysing ',
            ],
        ];
    }

    /**
     * @dataProvider misbehavingWorkerProvider
     *
     * @covers ::analyze
     * @covers ::readResults
     * @covers ::checkExited
     * @covers ::decodeCodeMap
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('misbehavingWorkerProvider')]
    public function test_misbehaving_worker_throws(string $phpCode, string $expectedMessage): void
    {
        $pool = new WorkerPool(new ParallelConfig(1), $this->getFactoryRunning($phpCode));

        $onResultCalled = false;
        try {
            $pool->analyze($this->getSourceFiles(1), function () use (&$onResultCalled): void {
                $onResultCalled = true;
            });
            self::fail('Expected a ParallelisationException.');
        } catch (ParallelisationException $e) {
            self::assertStringContainsString($expectedMessage, $e->getMessage());
        }

        self::assertFalse($onResultCalled);
    }

    /**
     * @covers ::analyze
     * @covers ::startWorker
     */
    public function test_worker_which_cannot_be_started_throws(): void
    {
        $factory = new class () extends WorkerProcessFactory {
            public function create(): Process
            {
                return new Process(['php'], '/strauss/directory/which/does/not/exist');
            }
        };

        $pool = new WorkerPool(new ParallelConfig(1), $factory);

        $this->expectException(ParallelisationException::class);
        $this->expectExceptionMessage('Could not start a worker process');

        $pool->analyze($this->getSourceFiles(1), function (): void {
        });
    }

    /**
     * A well-behaved fake worker: the result's map and error reach the callback, and "quit" ends the worker.
     *
     * @covers ::analyze
     * @covers ::sendNextChunk
     * @covers ::readResults
     */
    public function test_results_are_passed_to_the_callback(): void
    {
        $phpCode = <<<'PHP'
while (false !== ($line = fgets(STDIN))) {
    $request = json_decode($line, true);
    if ('quit' === $request['action']) {
        exit(0);
    }
    foreach ($request['files'] as $file) {
        echo json_encode(['action' => 'result', 'file' => $file, 'error' => 'Failed: ' . basename($file)]), "\n";
    }
    echo json_encode(['action' => 'chunk-done']), "\n";
}
exit(1);
PHP;
        $files = $this->getSourceFiles(5);

        // Two files per chunk: the worker is sent three chunks, then "quit".
        $pool = new WorkerPool(new ParallelConfig(1, 2), $this->getFactoryRunning($phpCode));

        $results = [];
        $pool->analyze($files, function (string $path, ?FileCodeMap $codeMap, ?string $error) use (&$results): void {
            self::assertNull($codeMap);
            $results[$path] = $error;
        });

        self::assertSame($files, array_keys($results));
        self::assertSame('Failed: ' . basename($files[4]), $results[$files[4]]);
    }

    /**
     * @covers ::encodeCodeMap
     * @covers ::decodeCodeMap
     */
    public function test_code_map_survives_encoding(): void
    {
        $path = $this->getSourceFiles(1)[0];
        $codeMap = (new PhpFileAnalyzer())->analyze((string) file_get_contents($path), $path);
        self::assertInstanceOf(FileCodeMap::class, $codeMap);

        $encoded = WorkerPool::encodeCodeMap($codeMap);

        self::assertStringNotContainsString("\n", $encoded, 'It is sent as part of a single line.');
        self::assertEquals($codeMap, WorkerPool::decodeCodeMap($encoded));
    }

    /**
     * @covers ::__construct
     * @covers ::getConfig
     */
    public function test_get_config(): void
    {
        $config = new ParallelConfig(3, 7);

        self::assertSame($config, (new WorkerPool($config))->getConfig());
    }
}
