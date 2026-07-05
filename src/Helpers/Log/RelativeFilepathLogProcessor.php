<?php

namespace BrianHenryIE\Strauss\Helpers\Log;

use BrianHenryIE\Strauss\Helpers\FileSystem;
use Composer\InstalledVersions;
use Monolog\Processor\ProcessorInterface;

class RelativeFilepathLogProcessor
{

    public static function make(FileSystem $fileSystem): ProcessorInterface
    {
        return \Monolog\Logger::API === 2
                ? new RelativeFilepathLogProcessor2($fileSystem)
                : new RelativeFilepathLogProcessor3($fileSystem);
    }
}
