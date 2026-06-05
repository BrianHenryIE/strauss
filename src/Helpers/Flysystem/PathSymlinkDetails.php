<?php
/**
 * Immutable POJO with some convenience functions
 */
namespace BrianHenryIE\Strauss\Helpers\Flysystem;

/**
 * @phpstan-type PathSymlinkDetailsArray array{path:string,absoluteFilesystemPath:string,realpath:string,symlinkPath:string}
 */
class PathSymlinkDetails
{

    protected string $flysystemPath;
    protected string $absoluteFilesystemPath;
    protected string $targetAbsoluteFilesystemPath;
    protected string $symlinkAbsoluteFilesystemPath;
    protected string $symlinkTargetRealpathAbsoluteFilesystemPath;

    public function __construct(
        string $flysystemPath,
        string $absoluteFilesystemPath,
        string $targetAbsoluteFilesystemPath,
        string $symlinkAbsoluteFilesystemPath,
        string $symlinkTargetRealpathAbsoluteFilesystemPath
    ) {
        $this->flysystemPath = $flysystemPath;
        $this->absoluteFilesystemPath = $absoluteFilesystemPath;
        $this->targetAbsoluteFilesystemPath = $targetAbsoluteFilesystemPath;
        $this->symlinkAbsoluteFilesystemPath = $symlinkAbsoluteFilesystemPath;
        $this->symlinkTargetRealpathAbsoluteFilesystemPath = $symlinkTargetRealpathAbsoluteFilesystemPath;
    }

    /**
     * The path that was queried.
     */
    public function getFlysystemPath(): string
    {
        return $this->flysystemPath;
    }

    /**
     * The path-prefixed absolute path on the filesystem.
     */
    public function getAbsoluteFilesystemPath(): string
    {
        return $this->absoluteFilesystemPath;
    }

    /**
     * The `realpath()` for the queried path.
     */
    public function getTargetAbsoluteFilesystemPath(): string
    {
        return $this->targetAbsoluteFilesystemPath;
    }

    /**
     * The symlink, potentially a parent directory, which is the root of the virtual file/directory.
     */
    public function getSymlinkAbsoluteFilesystemPath(): string
    {
        return $this->symlinkAbsoluteFilesystemPath;
    }

    /**
     * The symlink's target.
     */
    public function getSymlinkTargetRealpathAbsoluteFilesystemPath(): string
    {
        return $this->symlinkTargetRealpathAbsoluteFilesystemPath;
    }

    /**
     * @return PathSymlinkDetailsArray
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * Is the path itself a symlink (conversely it would be inside a symlink)
     */
    public function isSymlink(): bool
    {
        return $this->absoluteFilesystemPath === $this->symlinkAbsoluteFilesystemPath;
    }
}
