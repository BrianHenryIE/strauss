<?php

namespace BrianHenryIE\Strauss\Tests\Unit\Console\Commands;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\Pipeline\Parallel\WorkerPool;
use BrianHenryIE\Strauss\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArgvInput;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Console\Commands\DependenciesCommand
 */
class DependenciesCommandParallelTest extends TestCase
{
    /**
     * The command with its config and logger set, without running it.
     */
    protected function getSut(StraussConfig $config): DependenciesCommand
    {
        return new class ($config, $this->getLogger()) extends DependenciesCommand {
            /** @var string[] */
            public array $stepsRun = [];

            public function __construct(StraussConfig $config, LoggerInterface $logger)
            {
                parent::__construct();
                $this->config = $config;
                $this->logger = $logger;
            }

            public function getWorkerPool(): ?WorkerPool
            {
                return parent::getWorkerPool();
            }

            public function timed(string $step): void
            {
                parent::timed($step);
            }

            protected function exampleStep(): void
            {
                $this->stepsRun[] = 'exampleStep';
            }
        };
    }

    protected function getConfig(bool $isDryRun, ?int $parallel): StraussConfig
    {
        $config = $this->createMock(StraussConfig::class);
        $config->method('isDryRun')->willReturn($isDryRun);
        $config->method('getParallel')->willReturn($parallel);
        return $config;
    }

    /**
     * Run `$test` on a machine which appears to have this many CPUs.
     */
    protected function withCpus(int $cpus, callable $test): void
    {
        $previous = $_SERVER['NUMBER_OF_PROCESSORS'] ?? null;
        $_SERVER['NUMBER_OF_PROCESSORS'] = (string) $cpus;
        try {
            $test();
        } finally {
            if (is_null($previous)) {
                unset($_SERVER['NUMBER_OF_PROCESSORS']);
            } else {
                $_SERVER['NUMBER_OF_PROCESSORS'] = $previous;
            }
        }
    }

    /**
     * @covers ::configure
     */
    public function test_parallel_option_is_optional_and_distinguishes_absent_from_bare(): void
    {
        $definition = $this->getSut($this->getConfig(false, null))->getDefinition();

        self::assertTrue($definition->hasOption('parallel'));
        self::assertTrue($definition->getOption('parallel')->isValueOptional());

        self::assertFalse((new ArgvInput(['strauss'], $definition))->getOption('parallel'), 'Not passed.');
        self::assertNull((new ArgvInput(['strauss', '--parallel'], $definition))->getOption('parallel'));
        self::assertSame('4', (new ArgvInput(['strauss', '--parallel=4'], $definition))->getOption('parallel'));
    }

    /**
     * One worker per spare CPU by default.
     *
     * @covers ::getWorkerPool
     */
    public function test_worker_pool_uses_spare_cpus(): void
    {
        $this->withCpus(8, function (): void {
            $pool = $this->getSut($this->getConfig(false, null))->getWorkerPool();

            self::assertInstanceOf(WorkerPool::class, $pool);
            self::assertSame(7, $pool->getConfig()->getMaxProcesses());
        });
    }

    /**
     * @covers ::getWorkerPool
     */
    public function test_worker_pool_is_capped_by_config(): void
    {
        $this->withCpus(8, function (): void {
            $pool = $this->getSut($this->getConfig(false, 3))->getWorkerPool();

            self::assertInstanceOf(WorkerPool::class, $pool);
            self::assertSame(3, $pool->getConfig()->getMaxProcesses());
        });
    }

    /**
     * @covers ::getWorkerPool
     */
    public function test_no_worker_pool_when_parallel_is_off(): void
    {
        $this->withCpus(8, function (): void {
            self::assertNull($this->getSut($this->getConfig(false, 1))->getWorkerPool());
        });
    }

    /**
     * @covers ::getWorkerPool
     */
    public function test_no_worker_pool_on_a_single_cpu(): void
    {
        $this->withCpus(1, function (): void {
            self::assertNull($this->getSut($this->getConfig(false, null))->getWorkerPool());
            self::assertNull($this->getSut($this->getConfig(false, 4))->getWorkerPool());
        });
    }

    /**
     * Worker processes read the real filesystem; a dry run's files are in memory.
     *
     * @covers ::getWorkerPool
     */
    public function test_no_worker_pool_during_dry_run(): void
    {
        $this->withCpus(8, function (): void {
            self::assertNull($this->getSut($this->getConfig(true, null))->getWorkerPool());
        });
    }

    /**
     * @covers ::timed
     */
    public function test_timed_runs_the_step_and_logs_its_duration(): void
    {
        $sut = $this->getSut($this->getConfig(false, null));

        $sut->timed('exampleStep');

        self::assertSame(['exampleStep'], $sut->stepsRun);

        $debugMessages = array_column($this->getTestLogger()->recordsByLevel['debug'] ?? [], 'message');
        self::assertCount(1, $debugMessages);
        self::assertMatchesRegularExpression('/^exampleStep took \d+\.\d{3}s$/', $debugMessages[0]);
    }
}
