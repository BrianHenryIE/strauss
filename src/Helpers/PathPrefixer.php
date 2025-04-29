<?php

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Helpers;

use function rtrim;
use function strlen;
use function substr;

class PathPrefixer
{
    /**
     * @var string
     */
    private $prefix = '';

    /**
     * @var string
     */
    private $separator = '/';

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
