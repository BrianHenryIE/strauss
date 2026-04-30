<?php

namespace BrianHenryIE\Strauss\Console\Commands;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\TestCase;
use Mockery;
use Monolog\Logger;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Console\Commands\AbstractRenamespacerCommand
 */
class AbstractRenamespacerCommandTest extends TestCase
{
    /**
     * @dataProvider provider_logger_processors_only_receive_visible_levels
     *
     * @param string[] $args
     * @param string[] $expectedLevels
     */
    public function test_logger_processors_only_receive_visible_levels(array $args, array $expectedLevels): void
    {
        $processor = $this->runProbeCommand($args);

        self::assertSame($expectedLevels, $processor->levels);
    }

    /**
     * @return array<string,array{0:string[],1:string[]}>
     */
    public static function provider_logger_processors_only_receive_visible_levels(): array
    {
        return [
            'default' => [[], ['NOTICE']],
            'info' => [['--info'], ['INFO', 'NOTICE']],
            'debug' => [['--debug'], ['DEBUG', 'INFO', 'NOTICE']],
            'dry-run' => [['--dry-run'], ['DEBUG', 'INFO', 'NOTICE']],
            'silent' => [['--silent'], []],
        ];
    }

    /**
     * @param string[] $args
     */
    private function runProbeCommand(array $args = []): CountingLogProcessor
    {
        $processor = new CountingLogProcessor();
        $command = new LoggingProbeCommand($processor);
        $input = new ArgvInput(array_merge(['probe'], $args));
        $output = new BufferedOutput();

        $exitCode = $command->run($input, $output);

        self::assertSame(0, $exitCode);

        return $processor;
    }
}

class LoggingProbeCommand extends AbstractRenamespacerCommand
{
    public function __construct(private CountingLogProcessor $processor)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('probe');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var StraussConfig&\Mockery\MockInterface $config */
        $config = Mockery::mock(StraussConfig::class);
        $config->shouldReceive('isDryRun')->andReturn($input->hasOption('dry-run') && $input->getOption('dry-run') !== false);
        $this->config = $config;

        parent::execute($input, $output);

        if ($this->logger instanceof Logger) {
            $this->logger->pushProcessor($this->processor);
        }

        $this->logger->debug('debug record');
        $this->logger->info('info record');
        $this->logger->notice('notice record');

        return self::SUCCESS;
    }
}

class CountingLogProcessor
{
    /** @var string[] */
    public array $levels = [];

    /**
     * @param array{level_name:string} $record
     * @return array{level_name:string}
     */
    public function __invoke(array $record): array
    {
        $this->levels[] = $record['level_name'];

        return $record;
    }
}
