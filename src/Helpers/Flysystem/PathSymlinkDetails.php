<?php
/**
 * POJO with some convenience functions.
 */
namespace BrianHenryIE\Strauss\Helpers\Flysystem;

/**
 * @phpstan-type PathSymlinkDetailsArray array{path:string,absoluteFilesystemPath:string,realpath:string,symlinkPath:string}
 */
class PathSymlinkDetails
{
    /**
     * The path that was queried.
     */
    public string $flysystemPath;

    /**
     * The path-prefixed absolute path on the filesystem.
     */
    public string $absoluteFilesystemPath;

    /**
     * The `realpath()` for the queried path.
     */
    public string $targetAbsoluteFilesystemPath;

    /**
     * The symlink, potentially a parent directory, which is the root of the virtual file/directory.
     */
    public string $symlinkAbsoluteFilesystemPath;

    /**
     * The symlink's target.
     */
    public string $symlinkTargetRealpathAbsoluteFilesystemPath;

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
     * Is the path itself a symlink (conversely it would be inside a symlink)
     */
    public function isSymlink(): bool
    {
        return $this->absoluteFilesystemPath === $this->symlinkAbsoluteFilesystemPath;
    }
}
