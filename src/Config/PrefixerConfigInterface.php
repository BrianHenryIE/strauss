<?php

namespace BrianHenryIE\Strauss\Config;

interface PrefixerConfigInterface
{

    public function getTargetDirectory();

    public function getNamespacePrefix();

    public function getClassmapPrefix();

    public function getConstantsPrefix();

    public function getExcludePackagesFromPrefixing();

    public function getExcludeNamespacesFromPrefixing();

    public function getExcludeFilePatternsFromPrefixing();
}
