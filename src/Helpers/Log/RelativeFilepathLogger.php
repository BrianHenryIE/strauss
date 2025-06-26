<?php
/**
 * A logger that changes file paths to be relative to the project directory.
 *
 * @see \BrianHenryIE\Strauss\Helpers\FileSystem::getProjectRelativePath()
 */

namespace BrianHenryIE\Strauss\Helpers\Log;

use BrianHenryIE\Strauss\Helpers\FileSystem;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

class RelativeFilepathLogger implements LoggerInterface
{
    use LoggerTrait;
    protected LoggerInterface $nextLogger;

    protected FileSystem $fileSystem;

    public function __construct(
        FileSystem $fileSystem,
        LoggerInterface $nextLogger
    ) {
        $this->fileSystem = $fileSystem;
        $this->nextLogger = $nextLogger;
    }

    /**
     * Checks all context values for keys containing 'path' modifies their values to be
     * relative to the project root.
     *
     * @param $level
     * @param $message
     * @param array $context
     *
     * @return void
     */
    public function log($level, $message, array $context = array())
    {
        foreach ($context as $key => $val) {
            if (false !== stripos($key, 'path') && is_string($val)) {
                $context[$key] = $this->fileSystem->getProjectRelativePath($val);
            }
        }

        $this->nextLogger->$level($message, $context);
    }
}
