<?php

namespace BrianHenryIE\Strauss\Config;

interface CleanupConfigInterface
{
    public function getVendorDirectory();

    public function isDeleteVendorFiles();

    public function isDeleteVendorPackages();

    public function getTargetDirectory();

    public function isDryRun();
}
