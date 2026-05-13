<?php
/**
 *
 * Flysystem 3.x requires PHP 8.
 *
 */

namespace BrianHenryIE\Strauss\Helpers;

/**
 * @see \League\Flysystem\FilesystemReader
 * @see \League\Flysystem\FilesystemOperator
 * @see \League\Flysystem\Filesystem
 */
interface FlysystemReaderBackCompatTraitInterface extends FlysystemAdapterBackCompatTraitInterface
{
    public function directoryExists(string $location): bool;
    public function has(string $location): bool;
}
