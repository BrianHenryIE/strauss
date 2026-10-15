<?php

/**
 * `FlysystemAdapterBackCompatTrait` needs to normalize paths.
 *
 * @package brianhenryie/strauss
 *
 * @see \League\Flysystem\FilesystemAdapter
 */

namespace BrianHenryIE\Strauss\Helpers\Flysystem;

use League\Flysystem\FilesystemReader;
use League\Flysystem\PathNormalizer;

/**
 * @see FlysystemAdapterBackCompatTrait
 */
interface FlysystemAdapterBackCompatTraitInterface extends PathNormalizer
{
    /**
     * Implementation is provided by {@see FlysystemAdapterBackCompatTrait::directoryExists()}.
     *
     * @param string $location Path of the directory to check, relative to the filesystem root.
     *
     * @see FilesystemReader::directoryExists()
     */
    public function directoryExists(string $location): bool;

    /**
     * This isn't strictly on v3 interface but is needed to implement `::directoryExists()`.
     *
     * @param string $path Path to normalize.
     *
     * @see PathNormalizer
     */
    public function normalizePath(string $path): string;
}
