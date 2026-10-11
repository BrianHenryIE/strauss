<?php
/**
 * A logger that changes file paths to be relative to the project directory.
 *
 * @package brianhenryie/strauss
 *
 * @see \BrianHenryIE\Strauss\Helpers\FileSystem::getProjectRelativePath()
 */

namespace BrianHenryIE\Strauss\Helpers\Log;

use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use Monolog\Processor\ProcessorInterface;

/**
 * For each string context value whose key contains `path` (case-insensitive), replaces it with
 * {@see FileSystem::getProjectRelativePath()}. Operates on the Monolog v2 array record, modifying the array in place.
 */
class RelativeFilepathLogProcessor2 implements ProcessorInterface
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
     */
    public function __invoke(array $record): array
    {
        $context = $record['context'];

        foreach ($context as $key => $val) {
            if (false !== stripos($key, 'path') && is_string($val)) {
                $record['context'][$key] = $this->fileSystem->getProjectRelativePath($val);
            }
        }

        return $record;
    }
}
