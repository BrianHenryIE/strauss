<?php

namespace BrianHenryIE\Strauss\Tests\Unit\Pipeline\Parallel;

use BrianHenryIE\Strauss\Pipeline\Parallel\ParallelConfig;
use BrianHenryIE\Strauss\TestCase;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\Parallel\ParallelConfig
 */
class ParallelConfigTest extends TestCase
{
    /**
     * @covers ::detect
     * @covers ::countCpus
     */
    public function test_detect_leaves_a_cpu_for_the_parent(): void
    {
        $cpus = ParallelConfig::countCpus();
        self::assertGreaterThanOrEqual(1, $cpus);

        $config = ParallelConfig::detect();

        self::assertSame(max(1, $cpus - 1), $config->getMaxProcesses());
    }

    /**
     * @covers ::detect
     */
    public function test_detect_is_capped_by_the_requested_maximum(): void
    {
        self::assertSame(1, ParallelConfig::detect(1)->getMaxProcesses());
        self::assertTrue(ParallelConfig::detect(1)->isSequential());
        self::assertLessThanOrEqual(2, ParallelConfig::detect(2)->getMaxProcesses());
        self::assertSame(1, ParallelConfig::detect(0)->getMaxProcesses(), 'Nonsense is treated as sequential.');
    }

    /**
     * @covers ::__construct
     */
    public function test_rejects_non_positive_values(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ParallelConfig(0);
    }

    /**
     * @covers ::getProcessesToSpawn
     */
    public function test_never_spawns_more_workers_than_chunks(): void
    {
        $config = new ParallelConfig(8, 10);

        self::assertSame(1, $config->getProcessesToSpawn(1));
        self::assertSame(1, $config->getProcessesToSpawn(10));
        self::assertSame(2, $config->getProcessesToSpawn(11));
        self::assertSame(8, $config->getProcessesToSpawn(1000));
    }

    /**
     * @return array<string, array{0:int, 1:int, 2:int}>
     */
    public static function nonPositiveProvider(): array
    {
        return [
            'no processes' => [0, 20, 120],
            'negative processes' => [-1, 20, 120],
            'empty chunks' => [2, 0, 120],
            'no timeout' => [2, 20, 0],
        ];
    }

    /**
     * @dataProvider nonPositiveProvider
     *
     * @covers ::__construct
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonPositiveProvider')]
    public function test_rejects_each_non_positive_value(int $maxProcesses, int $filesPerChunk, int $timeout): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ParallelConfig($maxProcesses, $filesPerChunk, $timeout);
    }

    /**
     * @covers ::__construct
     * @covers ::getMaxProcesses
     * @covers ::getFilesPerChunk
     * @covers ::getChunkTimeoutSeconds
     * @covers ::isSequential
     */
    public function test_getters_and_defaults(): void
    {
        $defaults = new ParallelConfig(4);

        self::assertSame(4, $defaults->getMaxProcesses());
        self::assertSame(ParallelConfig::DEFAULT_FILES_PER_CHUNK, $defaults->getFilesPerChunk());
        self::assertSame(ParallelConfig::DEFAULT_CHUNK_TIMEOUT_SECONDS, $defaults->getChunkTimeoutSeconds());
        self::assertFalse($defaults->isSequential());

        $custom = new ParallelConfig(2, 5, 30);

        self::assertSame(5, $custom->getFilesPerChunk());
        self::assertSame(30, $custom->getChunkTimeoutSeconds());
    }

    /**
     * @covers ::sequential
     */
    public function test_sequential(): void
    {
        $config = ParallelConfig::sequential();

        self::assertTrue($config->isSequential());
        self::assertSame(1, $config->getMaxProcesses());
        self::assertSame(1, $config->getProcessesToSpawn(1000));
    }

    /**
     * Windows, and CI which sets it, report the count in the environment.
     *
     * @covers ::countCpus
     * @covers ::detect
     */
    public function test_count_cpus_from_number_of_processors(): void
    {
        $previous = $_SERVER['NUMBER_OF_PROCESSORS'] ?? null;

        try {
            $_SERVER['NUMBER_OF_PROCESSORS'] = '6';
            self::assertSame(6, ParallelConfig::countCpus());
            self::assertSame(5, ParallelConfig::detect()->getMaxProcesses());
            self::assertSame(3, ParallelConfig::detect(3)->getMaxProcesses());
            self::assertSame(5, ParallelConfig::detect(64)->getMaxProcesses());

            $_SERVER['NUMBER_OF_PROCESSORS'] = '1';
            self::assertTrue(ParallelConfig::detect()->isSequential());
            self::assertTrue(ParallelConfig::detect(8)->isSequential());

            $_SERVER['NUMBER_OF_PROCESSORS'] = '0';
            self::assertSame(1, ParallelConfig::countCpus());

            $_SERVER['NUMBER_OF_PROCESSORS'] = 'many';
            self::assertGreaterThanOrEqual(1, ParallelConfig::countCpus(), 'Falls through to detection.');
        } finally {
            if (is_null($previous)) {
                unset($_SERVER['NUMBER_OF_PROCESSORS']);
            } else {
                $_SERVER['NUMBER_OF_PROCESSORS'] = $previous;
            }
        }
    }
}
