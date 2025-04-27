<?php
/**
 * Mostly so it is typed when debugging.
 *
 */

namespace BrianHenryIE\Strauss\Helpers;

class ModifiedFilesInMemoryFilesystemAdapter extends InMemoryFilesystemAdapter
{
    use FlysystemBackCompatTrait;
}
