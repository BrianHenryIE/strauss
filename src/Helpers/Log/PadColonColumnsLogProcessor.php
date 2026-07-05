<?php

namespace BrianHenryIE\Strauss\Helpers\Log;

use Composer\InstalledVersions;
use Monolog\Processor\ProcessorInterface;

class PadColonColumnsLogProcessor
{

    public static function make(): ProcessorInterface
    {
        return \Monolog\Logger::API === 2
                ? new PadColonColumnsLogProcessor2()
                : new PadColonColumnsLogProcessor3();
    }
}
