<?php

namespace BrianHenryIE\Strauss\Console\Commands;

use BrianHenryIE\Strauss\TestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

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
        self::assertSame($expectedLevels, $this->runProbeCommand($args));
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
     * @return string[]
     */
    private function runProbeCommand(array $args = []): array
    {
        $command = new LoggingProbeCommand();
        $input = new ArgvInput(array_merge(['probe'], $args));
        $output = new BufferedOutput();

        $exitCode = $command->run($input, $output);

        self::assertSame(0, $exitCode);

        return $command->getProcessedLevels();
    }
}
