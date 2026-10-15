<?php

/**
 * Flysystem does not have an interface for PathPrefixer.
 *
 * @package brianhenryie/strauss
 *
 * @see League\Flysystem\PathPrefixer
 */

namespace BrianHenryIE\Strauss\Helpers\Flysystem;

/**
 * Declares only {@see self::prefixPath()}, the method {@see FileSystem} and {@see SymlinkProtectFilesystemAdapter}
 * call, so either League's `PathPrefixer` or Strauss's {@see PathPrefixer} can be injected.
 */
interface PathPrefixerInterface
{
    public function prefixPath(string $path): string;

    public function stripPrefix(string $path): string;
}
