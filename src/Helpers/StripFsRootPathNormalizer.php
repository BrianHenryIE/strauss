<?php

namespace BrianHenryIE\Strauss\Helpers;

use Composer\Util\Platform;
use League\Flysystem\PathNormalizer;
use League\Flysystem\WhitespacePathNormalizer;

class StripFsRootPathNormalizer implements PathNormalizer
{
    /**
     * @var string[]|null
     */
    private ?array $fsRoots;

    private ?PathNormalizer $delegateNormalizer;

    /**
     * @param string|string[]|null $fsRoots
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
            $this->fsRoots ??
                  [
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
        $path   = preg_replace("#" . $pattern . "#i", '', $path)
                  ?? (function () {
                    throw new \Exception(preg_last_error_msg(), preg_last_error());
                  })();

        if ($this->delegateNormalizer !== null) {
            $path = $this->delegateNormalizer->normalizePath($path);
        }

        return $path;
    }
}
