<?php
/**
 * Classes that use {@see FlysystemBackCompatTrait} must implement this interface.
 */

namespace BrianHenryIE\Strauss\Helpers;

use League\Flysystem\PathNormalizer;

interface FlysystemBackCompatTraitInterface
{

    public function getNormalizer(): PathNormalizer;
}
