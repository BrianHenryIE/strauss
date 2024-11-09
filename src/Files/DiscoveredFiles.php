<?php

namespace BrianHenryIE\Strauss\Files;

use BrianHenryIE\Strauss\Composer\ComposerPackage;

class DiscoveredFiles
{
    /** @var array<string,File> */
    protected array $files = [];

    public function add(FileBase $file): void
    {
        $this->files[$file->getSourcePath()] = $file;
    }

    /**
     * @return array<File|FileWithDependency>
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    public function getFile(string $sourceAbsolutePath): ?FileBase
    {
        return $this->files[$sourceAbsolutePath] ?? null;
    }
}
