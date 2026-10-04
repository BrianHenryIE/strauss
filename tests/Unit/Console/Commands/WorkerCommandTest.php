<?php

namespace BrianHenryIE\Strauss\Tests\Unit\Console\Commands;

use BrianHenryIE\Strauss\Console\Application;
use BrianHenryIE\Strauss\Console\Commands\WorkerCommand;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\PhpFileAnalyzer;
use BrianHenryIE\Strauss\Pipeline\Parallel\WorkerPool;
use BrianHenryIE\Strauss\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Runs the command in this process, with its stdin and stdout replaced.
 *
 * @see \BrianHenryIE\Strauss\Tests\Unit\Pipeline\Parallel\WorkerPoolTest for the command run as a real process.
 *
 * @coversDefaultClass \BrianHenryIE\Strauss\Console\Commands\WorkerCommand
 */
class WorkerCommandTest extends TestCase
{
    /**
     * @param string[] $requests The lines the parent process sends.
     *
     * @return array{0:int, 1:array<array<string, mixed>>} The exit code and the messages written.
     */
    protected function runWorker(array $requests): array
    {
        $stdin = fopen('php://memory', 'r+');
        self::assertIsResource($stdin);
        fwrite($stdin, implode("\n", $requests) . "\n");
        rewind($stdin);

        $sut = new class ($stdin) extends WorkerCommand {
            /** @var resource */
            protected $stdin;

            /** @var array<array<string, mixed>> */
            public array $written = [];

            /**
             * @param resource $stdin
             */
            public function __construct($stdin)
            {
                $this->stdin = $stdin;
                parent::__construct();
            }

            protected function getStdin()
            {
                return $this->stdin;
            }

            protected function write(array $message): void
            {
                $this->written[] = $message;
            }
        };

        try {
            $exitCode = $sut->run(new ArrayInput([]), new NullOutput());
        } finally {
            fclose($stdin);
        }

        return [$exitCode, $sut->written];
    }

    /**
     * @param string[] $files
     */
    protected function analyzeRequest(array $files): string
    {
        return (string) json_encode(['action' => WorkerPool::ACTION_ANALYZE, 'files' => $files]);
    }

    protected function quitRequest(): string
    {
        return (string) json_encode(['action' => WorkerPool::ACTION_QUIT, 'files' => []]);
    }

    /**
     * @covers ::configure
     * @covers \BrianHenryIE\Strauss\Console\Application::__construct
     */
    public function test_is_registered_and_hidden(): void
    {
        $application = new Application('1.0.0');

        $command = $application->find(WorkerCommand::NAME);

        self::assertInstanceOf(WorkerCommand::class, $command);
        self::assertTrue($command->isHidden());
    }

    /**
     * One result per file, in the order given, then "chunk done"; the map is what analysing in-process gives.
     *
     * @covers ::execute
     * @covers ::analyzeFile
     */
    public function test_analyzes_each_file_then_reports_chunk_done(): void
    {
        $srcDir = dirname(__DIR__, 4) . '/src';
        $files = [$srcDir . '/Pipeline/Parallel/Worker.php', $srcDir . '/Pipeline/Parallel/ParallelConfig.php'];

        [$exitCode, $written] = $this->runWorker([
            $this->analyzeRequest($files),
            $this->quitRequest(),
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(3, $written);
        self::assertSame(['action' => WorkerPool::ACTION_CHUNK_DONE], $written[2]);

        $analyzer = new PhpFileAnalyzer();
        foreach ($files as $index => $path) {
            self::assertSame(WorkerPool::ACTION_RESULT, $written[$index]['action']);
            self::assertSame($path, $written[$index]['file']);
            self::assertNull($written[$index]['error']);
            self::assertEquals(
                $analyzer->analyze((string) file_get_contents($path), $path),
                WorkerPool::decodeCodeMap($written[$index]['map'])
            );
        }
    }

    /**
     * A file which cannot be read or parsed is that file's result, and the rest of the chunk is still analysed.
     *
     * @covers ::execute
     * @covers ::analyzeFile
     */
    public function test_unreadable_and_unparsable_files_are_results_not_failures(): void
    {
        $missing = sys_get_temp_dir() . '/strauss-does-not-exist-' . uniqid() . '.php';
        $unparsable = tempnam(sys_get_temp_dir(), 'strauss-unparsable') . '.php';
        file_put_contents($unparsable, "<?php\nnamespace %g_namespace%\\AdminMenus;\n");
        $readable = dirname(__DIR__, 4) . '/src/Pipeline/Parallel/Worker.php';

        try {
            [$exitCode, $written] = $this->runWorker([
                $this->analyzeRequest([$missing, $unparsable, $readable]),
                $this->quitRequest(),
            ]);
        } finally {
            unlink($unparsable);
        }

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(4, $written);

        self::assertSame(
            ['action' => WorkerPool::ACTION_RESULT, 'file' => $missing, 'map' => null, 'error' => 'Could not read file.'],
            $written[0]
        );

        self::assertSame($unparsable, $written[1]['file']);
        self::assertNull($written[1]['map']);
        self::assertStringContainsString('Syntax error', $written[1]['error']);

        self::assertSame($readable, $written[2]['file']);
        self::assertIsString($written[2]['map']);
        self::assertNull($written[2]['error']);

        self::assertSame(['action' => WorkerPool::ACTION_CHUNK_DONE], $written[3]);
    }

    /**
     * @covers ::execute
     */
    public function test_handles_several_chunks_and_ignores_blank_lines(): void
    {
        $file = dirname(__DIR__, 4) . '/src/Pipeline/Parallel/Worker.php';

        [$exitCode, $written] = $this->runWorker([
            '',
            $this->analyzeRequest([$file]),
            '   ',
            $this->analyzeRequest([]),
            $this->quitRequest(),
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(
            [WorkerPool::ACTION_RESULT, WorkerPool::ACTION_CHUNK_DONE, WorkerPool::ACTION_CHUNK_DONE],
            array_column($written, 'action')
        );
    }

    /**
     * @covers ::execute
     */
    public function test_nothing_is_read_after_quit(): void
    {
        [$exitCode, $written] = $this->runWorker([
            $this->quitRequest(),
            $this->analyzeRequest([dirname(__DIR__, 4) . '/src/Pipeline/Parallel/Worker.php']),
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([], $written);
    }

    /**
     * The parent closing stdin without sending "quit" (e.g. it was killed) ends the worker cleanly.
     *
     * @covers ::execute
     */
    public function test_exits_when_stdin_closes(): void
    {
        [$exitCode, $written] = $this->runWorker([$this->analyzeRequest([])]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([['action' => WorkerPool::ACTION_CHUNK_DONE]], $written);
    }

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function unexpectedRequestProvider(): array
    {
        return [
            'not json' => ['not json', 'Unexpected request: not json'],
            'no action' => ['{"files":[]}', 'Unexpected request: {"files":[]}'],
            'not an object' => ['"analyze"', 'Unexpected request: "analyze"'],
            'unknown action' => ['{"action":"dance"}', 'Unexpected action: dance'],
        ];
    }

    /**
     * Anything unexpected is reported to the parent as an error message and the worker stops.
     *
     * @dataProvider unexpectedRequestProvider
     *
     * @covers ::execute
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unexpectedRequestProvider')]
    public function test_unexpected_request_is_reported_as_an_error(string $request, string $expectedMessage): void
    {
        [$exitCode, $written] = $this->runWorker([
            $request,
            $this->analyzeRequest([dirname(__DIR__, 4) . '/src/Pipeline/Parallel/Worker.php']),
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertCount(1, $written);
        self::assertSame(WorkerPool::ACTION_ERROR, $written[0]['action']);
        self::assertStringContainsString('RuntimeException: ' . $expectedMessage, $written[0]['message']);
    }
}
