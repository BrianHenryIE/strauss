<?php
/**
 * A logger that changes file paths to be relative to the project directory.
 *
 * @see \BrianHenryIE\Strauss\Helpers\FileSystem::getProjectRelativePath()
 */

namespace BrianHenryIE\Strauss\Helpers\Log;

use BrianHenryIE\Strauss\Helpers\FileSystem;
use CommunityHive\App\Illuminate\Support\Facades\Log;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class RelativeFilepathLogProcessor3 implements ProcessorInterface
{
    protected FileSystem $fileSystem;

    public function __construct(
        FileSystem $fileSystem
    ) {
        $this->fileSystem = $fileSystem;
    }

    /**
     * Checks all context values for keys containing 'path' modifies their values to be
     * relative to the project root.
     *
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $recordArray = (array) $record;

        $context = $recordArray['context'];

        foreach ($context as $key => $val) {
            if (false !== stripos($key, 'path') && is_string($val)) {
                $recordArray['context'][$key] = $this->fileSystem->getProjectRelativePath($val);
            }
        }
        return new LogRecord(...$recordArray);
    }
}
