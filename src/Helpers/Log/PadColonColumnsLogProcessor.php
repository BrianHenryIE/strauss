<?php
/**
 * Attempt to align text following `:` in a log message.
 *
 * But use `:::` to indicate it should be padded.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Helpers\Log;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class PadColonColumnsLogProcessor implements ProcessorInterface
{
    /** @var int $padLength */
    protected int $padLength = 0;

    public function __invoke(LogRecord $record): LogRecord
    {
        $message = $record->message;

        $messageParts = explode(':::', $message, 2);

        /**
         * @see https://github.com/BrianHenryIE/strauss/pull/231#pullrequestreview-3600736232
         */
        if (count($messageParts) < 2) {
            return $record;
        }

        $this->padLength = max($this->padLength, strlen($messageParts[0]) + 1);

        $messageParts[0] = $this->pad($messageParts[0], $this->padLength);

        return $record->with(message: implode('', $messageParts));
    }

    private function pad(string $text, int $padLength): string
    {
        $padded = str_pad($text, $padLength, ' ', STR_PAD_RIGHT);
        return str_replace($text, $text . ':', $padded);
    }
}
