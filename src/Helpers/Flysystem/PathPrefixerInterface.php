<?php
/**
 * Flysystem does not have an interface for PathPrefixer.
 *
 * @see League\Flysystem\PathPrefixer
 */

namespace BrianHenryIE\Strauss\Helpers\Flysystem;

interface PathPrefixerInterface
{

    public function prefixPath(string $path): string;
}
