<?php
/**
 * Factory for the Monolog processor which aligns log message text following a `:::` marker into a column.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Helpers\Log;

use Composer\InstalledVersions;
use Monolog\Processor\ProcessorInterface;

/**
 * Checks `\Monolog\Logger::API` at runtime and returns {@see PadColonColumnsLogProcessor2} for Monolog v2 (array
 * records) or {@see PadColonColumnsLogProcessor3} for Monolog v3 ({@see \Monolog\LogRecord} objects).
 */
class PadColonColumnsLogProcessor
{

    public static function make(): ProcessorInterface
    {
        /**
 * @phpstan-ignore identical.alwaysTrue (Depends on what version of PHP Strauss installed on)
*/
        return \Monolog\Logger::API === 2
                ? new PadColonColumnsLogProcessor2()
                : new PadColonColumnsLogProcessor3();
    }
}
