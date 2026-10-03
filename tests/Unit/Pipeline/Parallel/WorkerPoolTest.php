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
}
