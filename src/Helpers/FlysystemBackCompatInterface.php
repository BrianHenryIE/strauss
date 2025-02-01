<?php
/**
 * These methods were added in FlySystem x (TODO)
 */

namespace BrianHenryIE\Strauss\Helpers;

use League\Flysystem\FilesystemOperator;

interface FlysystemBackCompatInterface extends FilesystemOperator
{
    public function directoryExists(string $location): bool;
    public function has(string $location): bool;
}
