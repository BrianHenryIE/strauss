<?php

namespace BrianHenryIE\Strauss\Files;

class DiscoveredFiles
{
    /** @var array<string,FileBase|File|FileWithDependency> */
    protected array $files = [];

    public function add(FileBase $file): void
    {
        $this->files[$file->getSourcePath()] = $file;
    }

    /**
     * @return array<string,FileBase|File|FileWithDependency>
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * Fetch/check if a file exists in the discovered files.
     *
     * @param string $sourceAbsolutePath Full path to the file.
     */
    public function getFile(string $sourceAbsolutePath): ?FileBase
    {
        return $this->files[$sourceAbsolutePath] ?? null;
    }

    public function sort(): void
    {
        ksort($this->files);
    }
}
