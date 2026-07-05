<?php

namespace BrianHenryIE\Strauss\Helpers\Log;

use BrianHenryIE\Strauss\Helpers\FileSystem;
use Composer\InstalledVersions;
use Monolog\Processor\ProcessorInterface;

class RelativeFilepathLogProcessor
{

    public static function make(FileSystem $fileSystem): ProcessorInterface
    {
        if (1 === preg_match('/^\D*(\d+)/', InstalledVersions::getVersion('monolog/monolog'), $matches)) {
            $majorVersion = (int) $matches[1];
            return 2 === $majorVersion
                ? new RelativeFilepathLogProcessor2($fileSystem)
                : new RelativeFilepathLogProcessor3($fileSystem);
        }

        throw new \Exception('Failed to get monolog version');
    }
}
