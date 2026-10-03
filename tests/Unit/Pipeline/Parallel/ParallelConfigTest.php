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
}
