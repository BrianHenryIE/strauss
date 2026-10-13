<?php

/**
 * A Flysystem path normalizer which removes the filesystem root (e.g. `/` or `C:\`) from the start of absolute
 * paths so they can be used as Flysystem-relative paths, including on Windows.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Helpers\Flysystem;

use Composer\Util\Platform;
use League\Flysystem\PathNormalizer;
use League\Flysystem\WhitespacePathNormalizer;

/**
 * Builds a case-insensitive regex from the configured roots (defaulting to the current working directory's root and
 * `c:` variants via {@see FileSystem::getFsRoot()}), strips the first match from the path, then passes the result to
 * a delegate {@see PathNormalizer}, by default League's {@see WhitespacePathNormalizer}.
 */
class StripFsRootPathNormalizer implements PathNormalizer
{
    /**
     * @var string[]|null
     */
    private ?array $fsRoots;

    private PathNormalizer $delegateNormalizer;

    /**
     * @param string|string[]|null $fsRoots            Filesystem root(s) to strip, e.g. `/` or `C:\`; null for the cwd's root and `c:` variants.
     * @param ?PathNormalizer      $delegateNormalizer Normalizes the path after the root is stripped; null for League's `WhitespacePathNormalizer`.
     */
    public function __construct(
        $fsRoots = null,
        ?PathNormalizer $delegateNormalizer = null
    ) {
        $this->fsRoots = is_string($fsRoots)
            ? [ $fsRoots ]
            : $fsRoots;
        $this->delegateNormalizer = $delegateNormalizer
            ?: new WhitespacePathNormalizer();
    }

    public function normalizePath(string $path): string
    {
        $fsRoots = array_unique(
            $this->fsRoots
                  ?? [
                      FileSystem::getFsRoot(Platform::getCwd()),
                      FileSystem::normalizeDirSeparator(FileSystem::getFsRoot(Platform::getCwd())),
                      'c:\\',
                      'c:/',
                  ]
        );

        $pattern = '^(' . implode(
            '|',
            array_map(
                fn($str) => str_replace(
                    '\\',
                    '\\\\',
                    str_replace(
                        '\/',
                        '\\\/',
                        $str
                    )
                ),
                $fsRoots
            )
        ) . ')';
        $regexedPath   = preg_replace("#" . $pattern . "#i", '', $path)
                  ?? (function () {
                      throw new \Exception(preg_last_error_msg(), preg_last_error());
                  })();

        $delegateNormalizedPath = $this->delegateNormalizer->normalizePath($regexedPath);

        return $delegateNormalizedPath;
    }
}
