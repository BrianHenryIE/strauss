<?php
/**
 * Attempt to align text following `:` in a log message.
 *
 * But use `:::` to indicate it should be padded.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Helpers\Log;

use BrianHenryIE\Strauss\Helpers\FileSystem;
use DateTimeInterface;
use Monolog\Processor\ProcessorInterface;

/**
 * @phpstan-type MonologRecordArray array{message: string, context: array<string,mixed>, level: 100|200|250|300|400|500|550|600, level_name: 'ALERT'|'CRITICAL'|'DEBUG'|'EMERGENCY'|'ERROR'|'INFO'|'NOTICE'|'WARNING', channel: string, datetime: DateTimeInterface, extra: array<mixed>}
 */
class PadColonColumnsLogProcessor implements ProcessorInterface
{
    /** @var int $padLength */
    protected int $padLength = 0;

    /**
     * @param MonologRecordArray $record
     * @return MonologRecordArray
     */
    public function __invoke(array $record): array
    {
        $message = $record['message'];

        $messageParts = explode(':::', $message, 2);

        $this->padLength = max($this->padLength, strlen($messageParts[0]) + 1);

        $messageParts[0] = $this->pad($messageParts[0], $this->padLength);

        $record['message'] = implode('', $messageParts);

        return $record;
    }

    private function pad(string $text, int $padLength): string
    {
        $padded = str_pad($text, $padLength, ' ', STR_PAD_RIGHT);
        return str_replace($text, $text . ':', $padded);
    }
}
