<?php
/**
 * `FilesystemReader` interface v3 introduced `::directoryExists()` and `::has()`.
 *
 * When this trait is used with your implementation of {@see League\Flysystem\FilesystemReader} and `league/flysystem`
 * v3 is installed, the genuine parent methods are used, when v2 is installed the trait provides implementations.
 *
 * directoryExists, has
 * @see https://github.com/thephpleague/flysystem/blob/2.x/src/FilesystemReader.php
 * @see https://github.com/thephpleague/flysystem/blob/3.x/src/FilesystemReader.php
 *
 * @see https://github.com/thephpleague/flysystem/blob/3.x/src/Filesystem.php#L46-L51
 *
 * `FilesystemAdapter` 3.x also introduced `::directoryExists()` so we re-use its compatability trait here.
 * @see FlysystemAdapterBackCompatTrait
 */

namespace BrianHenryIE\Strauss\Helpers;

use League\Flysystem\FilesystemReader;

/**
 * @mixin FlysystemReaderBackCompatTraitInterface
 *
 * @method string normalizePath($location)
 */
trait FlysystemReaderBackCompatTrait
{
    use FlysystemAdapterBackCompatTrait;

    /**
     * @param string $location
     *
     * @return bool
     * @throws \League\Flysystem\FilesystemException
     */
    public function has(string $location): bool
    {
        /**
         * @phpstan-ignore booleanAnd.leftAlwaysTrue
         */
        if (get_parent_class(self::class) && method_exists(get_parent_class(self::class), 'has')) {
            /** @phpstan-ignore staticMethod.notFound */
            return parent::has($location);
        }

        return $this->fileExists($this->normalizePath($location)) || $this->directoryExists($this->normalizePath($location));
    }
}
