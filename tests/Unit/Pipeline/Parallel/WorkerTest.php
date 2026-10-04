<?php

namespace BrianHenryIE\Strauss\Tests\Unit\Pipeline\Parallel;

use BrianHenryIE\Strauss\Pipeline\Parallel\Worker;
use BrianHenryIE\Strauss\Pipeline\Parallel\WorkerPool;
use BrianHenryIE\Strauss\TestCase;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\Parallel\Worker
 */
class WorkerTest extends TestCase
{
    /**
     * @param string[] $outputs What the process returns from each successive `getIncrementalOutput()`.
     */
    protected function getSut(array $outputs = [], ?InputStream $input = null): Worker
    {
        $process = $this->createMock(Process::class);
        $process->method('getIncrementalOutput')->willReturnOnConsecutiveCalls(...$outputs);

        return new Worker($process, $input ?? new InputStream());
    }

    /**
     * @covers ::__construct
     * @covers ::send
     */
    public function test_send_writes_one_json_line(): void
    {
        $input = new InputStream();
        $sut = $this->getSut([], $input);

        $sut->send([WorkerPool::ACTION_ANALYZE, ['/a/one.php', '/a/two.php']]);
        $sut->send([WorkerPool::ACTION_QUIT]);

        $written = $input->getIterator();

        self::assertSame(
            '{"action":"analyze","files":["\/a\/one.php","\/a\/two.php"]}' . "\n",
            $written->current()
        );
        $written->next();
        self::assertSame('{"action":"quit","files":[]}' . "\n", $written->current());
    }

    /**
     * A line split across reads is only returned once its newline arrives.
     *
     * @covers ::readLines
     */
    public function test_read_lines_buffers_partial_lines(): void
    {
        $sut = $this->getSut(['{"action":"res', 'ult"}' . "\n" . '{"action":"chunk-done"}' . "\n" . '{"act', '']);

        self::assertSame([], $sut->readLines());
        self::assertSame('{"action":"res', $sut->buffer);

        self::assertSame(['{"action":"result"}', '{"action":"chunk-done"}'], $sut->readLines());
        self::assertSame('{"act', $sut->buffer);

        self::assertSame([], $sut->readLines());
        self::assertSame('{"act', $sut->buffer);
    }

    /**
     * @covers ::readLines
     */
    public function test_read_lines_skips_blank_lines_and_trims(): void
    {
        $sut = $this->getSut(["\n  \r\n one \r\ntwo\n"]);

        self::assertSame(['one', 'two'], $sut->readLines());
        self::assertSame('', $sut->buffer);
    }

    /**
     * A worker is ready for another chunk when it has none outstanding and has not been told to quit.
     *
     * @covers ::isChunkDone
     */
    public function test_is_chunk_done(): void
    {
        $input = new InputStream();
        $sut = $this->getSut([], $input);

        self::assertTrue($sut->isChunkDone());

        $sut->chunkStartedAt = microtime(true);
        self::assertFalse($sut->isChunkDone());

        $sut->chunkStartedAt = null;
        $input->close();
        self::assertFalse($sut->isChunkDone(), 'Its input was closed: it was told to quit.');
    }

    /**
     * @covers ::isChunkTimedOut
     */
    public function test_is_chunk_timed_out(): void
    {
        $sut = $this->getSut();

        self::assertFalse($sut->isChunkTimedOut(1), 'An idle worker cannot time out.');

        $sut->chunkStartedAt = microtime(true);
        self::assertFalse($sut->isChunkTimedOut(60));

        $sut->chunkStartedAt = microtime(true) - 61;
        self::assertTrue($sut->isChunkTimedOut(60));
    }
}
