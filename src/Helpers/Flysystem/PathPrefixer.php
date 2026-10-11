<?php
/**
 * Converts between Flysystem-relative paths and absolute filesystem paths by adding or removing a base path prefix.
 *
 * Strauss's own version of {@see \League\Flysystem\PathPrefixer}, implementing {@see PathPrefixerInterface}.
 *
 * @package brianhenryie/strauss
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Helpers\Flysystem;

use function rtrim;
use function strlen;
use function substr;

/**
 * Stores the prefix with any trailing slash replaced by the configured separator. Unlike League's implementation,
 * {@see self::prefixPath()} does not double-prefix a path which already starts with the prefix, and an empty prefix
 * (relative paths) stays empty rather than becoming a lone separator.
 */
class PathPrefixer implements PathPrefixerInterface
{
    /**
     * @var string
     */
    private $prefix = '';

    /**
     * @var string
     */
    private string $separator = '/';

    public function __construct(string $prefix, string $separator = '/')
    {
        $this->prefix = in_array(substr($prefix, -1), ['\\','/'])
            ? substr($prefix, 0, -1)
            : $prefix;

        if ($this->prefix !== '' || $prefix === $separator) {
            $this->prefix .= $separator;
        }

        $this->separator = $separator;
    }

    public function prefixPath(string $path): string
    {
        return str_starts_with($path, $this->prefix)
            ? $path
            : $this->prefix . ltrim($path, '\\/');
    }

    public function stripPrefix(string $path): string
    {
        /* @var string */
        return substr($path, strlen($this->prefix));
    }

    public function stripDirectoryPrefix(string $path): string
    {
        return rtrim($this->stripPrefix($path), '\\/');
    }

    public function prefixDirectoryPath(string $path): string
    {
        $prefixedPath = $this->prefixPath(rtrim($path, '\\/'));

        if ((substr($prefixedPath, -1) === $this->separator) || $prefixedPath === '') {
            return $prefixedPath;
        }

        return $prefixedPath . $this->separator;
    }
}
