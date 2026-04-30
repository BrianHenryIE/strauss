<?php

namespace BrianHenryIE\Strauss\Console\Commands;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use Mockery;
use Monolog\Logger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LoggingProbeCommand extends AbstractRenamespacerCommand
{
    /** @var string[] */
    private array $processedLevels = [];

    protected function configure()
    {
        $this->setName('probe');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->processedLevels = [];

        /** @var StraussConfig&\Mockery\MockInterface $config */
        $config = Mockery::mock(StraussConfig::class);
        $config->shouldReceive('isDryRun')->andReturn($input->hasOption('dry-run') && $input->getOption('dry-run') !== false);
        $this->config = $config;

        parent::execute($input, $output);

        if ($this->logger instanceof Logger) {
            $this->logger->pushProcessor(function (array $record): array {
                $this->processedLevels[] = $record['level_name'];

                return $record;
            });
        }

        $this->logger->debug('debug record');
        $this->logger->info('info record');
        $this->logger->notice('notice record');

        return self::SUCCESS;
    }

    /**
     * @return string[]
     */
    public function getProcessedLevels(): array
    {
        return $this->processedLevels;
    }
}
