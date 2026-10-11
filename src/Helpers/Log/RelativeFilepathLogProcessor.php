<?php
/**
 * Factory for the Monolog processor which rewrites absolute file paths in log context to be project-relative.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Helpers\Log;

use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use Monolog\Processor\ProcessorInterface;

/**
 * Checks `\Monolog\Logger::API` at runtime and returns {@see RelativeFilepathLogProcessor2} for Monolog v2 (array
 * records) or {@see RelativeFilepathLogProcessor3} for Monolog v3 ({@see \Monolog\LogRecord} objects), each given
 * the {@see FileSystem}.
 */
class RelativeFilepathLogProcessor
{

    public static function make(FileSystem $fileSystem): ProcessorInterface
    {
        /**
 * @phpstan-ignore identical.alwaysTrue (Depends on what version of PHP Strauss installed on)
*/
        return \Monolog\Logger::API === 2
                ? new RelativeFilepathLogProcessor2($fileSystem)
                : new RelativeFilepathLogProcessor3($fileSystem);
    }
}
