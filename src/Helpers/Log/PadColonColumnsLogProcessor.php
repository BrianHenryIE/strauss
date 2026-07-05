<?php

namespace BrianHenryIE\Strauss\Helpers\Log;

use Composer\InstalledVersions;
use Monolog\Processor\ProcessorInterface;

class PadColonColumnsLogProcessor
{

    public static function make(): ProcessorInterface
    {

        if (1 === preg_match('/^\D*(\d+)/', InstalledVersions::getVersion('monolog/monolog'), $matches)) {
            $majorVersion = (int) $matches[1];
            return 2 === $majorVersion
                ? new PadColonColumnsLogProcessor2()
                : new PadColonColumnsLogProcessor3();
        }

        throw new \Exception('Failed to get monolog verions');
    }
}
