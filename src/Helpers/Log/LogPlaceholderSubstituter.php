<?php
/**
 * @see https://www.garfieldtech.com/blog/psr-3-properly
 */

namespace BrianHenryIE\Strauss\Helpers\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

class LogPlaceholderSubstituter implements LoggerInterface
{
    use LoggerTrait;
    protected LoggerInterface $nextLogger;

    public function __construct(
        LoggerInterface $nextLogger
    ) {
        $this->nextLogger = $nextLogger;
    }

    public function log($level, $message, array $context = array())
    {
        foreach ($context as $key => $val) {
            if (is_string($val)) {
                $message = str_replace('{' . $key . '}', $val, $message);
            }
        }

        $this->nextLogger->$level($message, $context);
    }
}
