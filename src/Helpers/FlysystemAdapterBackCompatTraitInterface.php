<?php
/**
 * `FlysystemAdapterBackCompatTrait` needs to normalize paths.
 *
 * @see \League\Flysystem\FilesystemAdapter
 */

namespace BrianHenryIE\Strauss\Helpers;

use League\Flysystem\FilesystemReader;
use League\Flysystem\PathNormalizer;

/**
 * @see FlysystemAdapterBackCompatTrait
 */
interface FlysystemAdapterBackCompatTraitInterface
{
    /**
     * Implementation is provided by {@see FlysystemAdapterBackCompatTrait::directoryExists()}.
     *
     * @see FilesystemReader::directoryExists()
     */
    public function directoryExists(string $location): bool;

    /**
     * @see PathNormalizer
     */
    public function normalizePath(string $path): string;
}
