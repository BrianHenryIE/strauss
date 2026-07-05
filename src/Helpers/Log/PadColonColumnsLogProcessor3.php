<?php
/**
 * Align text following `:` in a log message.
 *
 * Use `:::` to indicate it should be padded.
 *
 * Monolog v3 compatible.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Helpers\Log;

use CommunityHive\App\Illuminate\Support\Facades\Log;
use DateTimeInterface;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class PadColonColumnsLogProcessor3 implements ProcessorInterface
{
    /** @var int $padLength */
    protected int $padLength = 0;

    /**
     * @param LogRecord $record
     * @return LogRecord
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $messageParts = explode(':::', $record->message, 2);

        /**
         * @see https://github.com/BrianHenryIE/strauss/pull/231#pullrequestreview-3600736232
         */
        if (count($messageParts) < 2) {
            return $record;
        }

        $this->padLength = max($this->padLength, strlen($messageParts[0]) + 1);

        $messageParts[0] = $this->pad($messageParts[0], $this->padLength);

        $recordArray = (array) $record;
        $recordArray['message'] = implode('', $messageParts);

        return new LogRecord(...$recordArray);
    }

    private function pad(string $text, int $padLength): string
    {
        $padded = str_pad($text, $padLength, ' ', STR_PAD_RIGHT);
        return str_replace($text, $text . ':', $padded);
    }
}
