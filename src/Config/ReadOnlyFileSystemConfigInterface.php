<?php

namespace BrianHenryIE\Strauss\Config;

interface ReadOnlyFileSystemConfigInterface
{
    public function getVendorDirectory(): string;

    public function getTargetDirectory(): string;
}
