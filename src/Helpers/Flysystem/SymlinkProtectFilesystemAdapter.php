<?php
/**
 * The idea is to prevent write/delete operations to symlinked files.
 *
 * This class extends LocalFilesystemAdapter and adds two new `linkHandling` modes: warn and throw.
 * * LocalFilesystemAdapter::SKIP_LINKS Silently does nothing (e.g. symlinks are not included in directory lists)
 * * LocalFilesystemAdapter::DISALLOW_LINKS Throws exceptions when symlinks are encountered for read or write
 * * SymlinkProtectFilesystemAdapter::WARN_LINKS Sends a message to LoggerInterface::warning() with appropriate context,
 *                                               then allows write operations.
 * * SymlinkProtectFilesystemAdapter::THROW_LINKS Throws UnableToWriteFile exception on write to a symlinked path
 *
 * Read operations act normally.
 * `::delete()` and `::deleteDirectory()` unlinks symlinks, messages LoggerInterface::notice().
 *
 * Outcome of read operation on symlinked files:
 * * Debug log every time a symlinked file is read
 *
 * Outcome of write/modify operation on non-symlinked files:
 * * standard behavior of LocalFilesystemAdapter
 *
 * Info log the first time each symlink is seen
 *
 * Your implementation of LoggerInterface can decide what to do with the log messages, e.g.
 * throw an exception on error, or just log them with an instruction on how to remedy the issue.
 *
 * @see \League\Flysystem\SymbolicLinkEncountered
 * @see \League\Flysystem\Local\LocalFilesystemAdapter::SKIP_LINKS
 * @see \League\Flysystem\Local\LocalFilesystemAdapter::DISALLOW_LINKS
 * @see \League\Flysystem\Local\LocalFilesystemAdapter::$linkHandling
 * @see \League\Flysystem\Local\LocalFilesystemAdapter::listContents()
 */

namespace BrianHenryIE\Strauss\Helpers\Flysystem;

use DirectoryIterator;
use FilesystemIterator;
use Generator;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PathNormalizer;
use League\Flysystem\SymbolicLinkEncountered;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\UnixVisibility\VisibilityConverter;
use League\Flysystem\WhitespacePathNormalizer;
use League\MimeTypeDetection\MimeTypeDetector;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

class SymlinkProtectFilesystemAdapter extends LocalFilesystemAdapter implements FlysystemAdapterBackCompatTraitInterface
{
    use FlysystemAdapterBackCompatTrait;
    use LoggerAwareTrait;

    public const WARN_LINKS = 0003;

    /**
     * @var int
     */
    public const THROW_LINKS = 0004;

    /**
     * Converts flysystem relative paths to filesystem absolute paths.
     *
     * @see LocalFilesystemAdapter::$prefixer
     *
     * @var \BrianHenryIE\Strauss\Helpers\Flysystem\PathPrefixerInterface|\League\Flysystem\PathPrefixer
     */
    protected $pathPrefixer;

    /**
     * @see LocalFilesystemAdapter::$linkHandling
     */
    protected int $linkHandling;

    /**
     * @see LocalFilesystemAdapter::$visibility
     * @var VisibilityConverter
     */
    private $visibility;

    /**
     * E.g. {@see WhitespacePathNormalizer} rejects invalid whitespace, converts `\` to `/`, and converts relative to absolute paths.
     */
    protected PathNormalizer $normalizer;

    /**
     * Record of discovered symlink paths
     * * prevent notifying more than once per discovered symlink
     * * allows faster lookup in future
     * * provides list of symlinked paths for "did we encounter any symlink" checks post operations
     *
     * @var array<string, string> Array of absolute filesystem paths : symlink target (`realpath()`).
     */
    protected array $symlinkTargetsMap = [];

    /**
     * @param \League\Flysystem\PathPrefixer|PathPrefixerInterface $pathPrefixer
     */
    public function __construct(
        string $location,
        ?PathNormalizer $pathNormalizer = null,
        $pathPrefixer = null,
        ?LoggerInterface $logger = null,
        ?VisibilityConverter $visibility = null,
        int $writeFlags = LOCK_EX,
        int $linkHandling = SymlinkProtectFilesystemAdapter::THROW_LINKS,
        ?MimeTypeDetector $mimeTypeDetector = null
    ) {
        parent::__construct($location, $visibility, $writeFlags, $linkHandling, $mimeTypeDetector);

        $this->normalizer = $pathNormalizer ?? new WhitespacePathNormalizer();
        $this->pathPrefixer = $pathPrefixer ?? new PathPrefixer($location, DIRECTORY_SEPARATOR);
        $this->setLogger($logger ?? new NullLogger());
        $this->visibility = $visibility ?: new PortableVisibilityConverter();
        $this->linkHandling = $linkHandling;
    }

    /**
     * @param string $path
     */
    protected function getSymlinkDetails(string $path): ?PathSymlinkDetails
    {
        $absoluteFilesystemPath = $this->pathPrefixer->prefixPath($path);
        $realpath = realpath($absoluteFilesystemPath);

        if ($realpath === false) {
            // File not found.
            throw new RuntimeException('Unable to realpath() absolute path: ' . $absoluteFilesystemPath);
        }
        if ($absoluteFilesystemPath === $realpath) {
            return null;
        }

        $symlink = $this->getSymlinkInPath($absoluteFilesystemPath);

        if (is_null($symlink)) {
            return null;
        }

        return new PathSymlinkDetails(
            $path,
            $absoluteFilesystemPath,
            $realpath,
            $symlink,
            $this->symlinkTargetsMap[ $symlink ]
        );
    }

    /**
     * Check is a file within a symlinked directory.
     *
     * Recursive function that runs `is_link()` on the path, drops the last part, loops until found or throws.
     *
     * @see \SplFileInfo::isLink()
     *
     * @see https://github.com/php/php-src/issues/12118
     *
     * @return ?string The filesystem path to the symlink, or null if none found.
     */
    protected function getSymlinkInPath(string $absoluteFilesystemPath): ?string
    {
        if ($absoluteFilesystemPath === '.' || $absoluteFilesystemPath === '/') {
            return null;
        }

        // Exact path is the symlink and it has already been recorded.
        if (isset($this->symlinkTargetsMap[ $absoluteFilesystemPath ])) {
            return $absoluteFilesystemPath;
        }

        if (is_link($absoluteFilesystemPath)) {
            $realpath = realpath($absoluteFilesystemPath);
            if ($realpath === false) {
//                return $this->getSymlinkInPath(dirname($absoluteFilesystemPath));
                throw new RuntimeException('symlink error');
            }
            $this->recordSymlink($absoluteFilesystemPath, $realpath);
            return $absoluteFilesystemPath;
        }

        return $this->getSymlinkInPath(dirname($absoluteFilesystemPath));
    }

    protected function recordSymlink(string $absoluteFilesystemPath, string $symlinkTargetRealpath): void
    {
        if (isset($this->symlinkTargetsMap[$absoluteFilesystemPath])) {
            return;
        }

        $this->symlinkTargetsMap[$absoluteFilesystemPath] = $symlinkTargetRealpath;

        $this->logger->info(
            "New symlink found at {source} target {target}.",
            [
                'source' => $absoluteFilesystemPath,
                'target' => $symlinkTargetRealpath,
            ]
        );
    }

    /**
     * Given the path to a file or folder, is it inside a symlinked directory?
     */
    public function isSymlinked(string $path): bool
    {
        $path = $this->normalizer->normalizePath($path);

        return !empty($this->getSymlinkDetails($path));
    }

    /**
     * @return array<string, string> Array of absolute paths which are symlinks : target realpath.
     */
    public function getSymlinks(): array
    {
        return $this->symlinkTargetsMap;
    }

    /**
     * Deleting a symlink is different on Windows and Linux.
     *
     * `unlink()` will not work on Windows. `rmdir()` will not work if there are files in the directory.
     * "On Windows, take care that `is_link()` returns false for Junctions."
     *
     * @see https://www.php.net/manual/en/function.is-link.php#113263
     * @see https://stackoverflow.com/a/18262809/336146
     * @throws FilesystemException
     */
    protected function removeSymlink(string $path): bool
    {
        $fullPath = $this->pathPrefixer->prefixPath($path);

        $this->logger->notice('Deleting symlink at ' . $fullPath . ' (points to ' . realpath($fullPath) . ')');

        if ($this->isWindowsOS()) {
            $success = rmdir($fullPath);
        } else {
            $success = unlink($fullPath);
        }

        return $success && (false === realpath($fullPath));
    }

    /**
     * Check are we running on Windows, whose symlink behaviour differs.
     *
     * Copied from {@see \Composer\Util\Platform::isWindows()}.
     */
    protected function isWindowsOS(): bool
    {
        return \defined('PHP_WINDOWS_VERSION_BUILD');
    }

    /**
     * @see FlysystemReaderBackCompatTrait::directoryExists()
     */
    public function normalizePath(string $path): string
    {
        return $this->normalizer->normalizePath($path);
    }

    /**
     *
     *
     * @see FilesystemAdapter::write()
     * @see FilesystemAdapter::ensureDirectoryExists()
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $path = $this->normalizer->normalizePath($path);

        $absoluteFilesystemPath = $this->pathPrefixer->prefixPath($path);
        $symlink = $this->getSymlinkInPath($absoluteFilesystemPath);

        if (!$symlink) {
            parent::write($path, $contents, $config);
            return;
        }

        $symlinkDetails = $this->getSymlinkDetails($this->pathPrefixer->stripPrefix($symlink));

        switch ($this->linkHandling) {
            case LocalFilesystemAdapter::SKIP_LINKS:
                return;
            case LocalFilesystemAdapter::DISALLOW_LINKS:
                throw SymbolicLinkEncountered::atLocation($path);
            case SymlinkProtectFilesystemAdapter::WARN_LINKS:
                $this->logger->warning(
                    "Writing symlinked file {flysystemPath} realpath {targetAbsoluteFilesystemPath}",
                    array_merge(
                        ['operation' => __METHOD__],
                        (array) $symlinkDetails
                    )
                );
                parent::write($path, $contents, $config);
                return;
            case SymlinkProtectFilesystemAdapter::THROW_LINKS:
            default:
                throw UnableToWriteFile::atLocation($path, 'symlink');
        }
    }

    /**
     * @throws FilesystemException
     * @see FilesystemAdapter::writeStream()
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $path = $this->normalizer->normalizePath($path);


        $absoluteFilesystemPath = $this->pathPrefixer->prefixPath($path);
        $symlink = $this->getSymlinkInPath($absoluteFilesystemPath);

        if (!$symlink) {
            parent::writeStream($path, $contents, $config);
            return;
        }

        $symlinkDetails = $this->getSymlinkDetails($symlink);

        switch ($this->linkHandling) {
            case LocalFilesystemAdapter::SKIP_LINKS:
                return;
            case LocalFilesystemAdapter::DISALLOW_LINKS:
                throw SymbolicLinkEncountered::atLocation($path);
            case SymlinkProtectFilesystemAdapter::WARN_LINKS:
                $this->logger->warning(
                    "Writing stream symlinked file {flysystemPath} realpath {targetAbsoluteFilesystemPath}",
                    array_merge(
                        ['operation' => __METHOD__],
                        (array) $symlinkDetails
                    )
                );
                parent::writeStream($path, $contents, $config);
                return;
            case SymlinkProtectFilesystemAdapter::THROW_LINKS:
            default:
                throw UnableToWriteFile::atLocation($path, 'symlink');
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $path = $this->normalizer->normalizePath($path);

        $symlinkDetails = $this->getSymlinkDetails($path);
        $isSymlinked = !empty($symlinkDetails);

        if (!$isSymlinked) {
            parent::setVisibility($path, $visibility);
            return;
        }

        switch ($this->linkHandling) {
            case LocalFilesystemAdapter::SKIP_LINKS:
                return;
            case LocalFilesystemAdapter::DISALLOW_LINKS:
                throw SymbolicLinkEncountered::atLocation($path);
            case SymlinkProtectFilesystemAdapter::WARN_LINKS:
                $this->logger->warning(
                    "Setting visibility of symlinked file {flysystemPath} realpath {targetAbsoluteFilesystemPath}",
                    array_merge(
                        ['operation' => __METHOD__],
                        (array) $symlinkDetails
                    )
                );
                parent::setVisibility($path, $visibility);
                return;
            case SymlinkProtectFilesystemAdapter::THROW_LINKS:
            default:
                throw UnableToSetVisibility::atLocation($path, 'symlink');
        }
    }

    public function delete(string $path): void
    {
        $path = $this->normalizer->normalizePath($path);

        $symlinkDetails = $this->getSymlinkDetails($path);
        $isSymlinked = !empty($symlinkDetails);

        if (!$isSymlinked) {
            parent::delete($path);
            return;
        }

        switch ($this->linkHandling) {
            case LocalFilesystemAdapter::SKIP_LINKS:
                return;
            case LocalFilesystemAdapter::DISALLOW_LINKS:
                throw SymbolicLinkEncountered::atLocation($path);
            case SymlinkProtectFilesystemAdapter::WARN_LINKS:
                if ($symlinkDetails->isSymlink()) {
                    $this->logger->warning(
                        "Deleting symlink at {flysystemPath}",
                        array_merge(
                            [ 'operation' => __METHOD__ ],
                            (array) $symlinkDetails
                        )
                    );
                    $didRemove = $this->removeSymlink($path);

                    if (!$didRemove) {
                        throw UnableToDeleteFile::atLocation($path, 'symlink target: ' . $symlinkDetails->symlinkTargetRealpathAbsoluteFilesystemPath);
                    }
                    return;
                } else {
                    $this->logger->warning(
                        "Deleting symlinked file {flysystemPath} realpath {targetAbsoluteFilesystemPath}",
                        array_merge(
                            [ 'operation' => __METHOD__ ],
                            (array) $symlinkDetails
                        )
                    );
                    parent::delete($path);

                    return;
                }
            case SymlinkProtectFilesystemAdapter::THROW_LINKS:
            default:
                throw UnableToDeleteFile::atLocation($path, 'symlink');
        }
    }

    public function deleteDirectory(string $path): void
    {
        $path = $this->normalizer->normalizePath($path);

        $symlinkDetails = $this->getSymlinkDetails($path);
        $isSymlinked = !empty($symlinkDetails);

        if (!$isSymlinked) {
            parent::deleteDirectory($path);
            return;
        }

        if ($symlinkDetails->isSymlink()) {
            $this->logger->notice(
                "Unlinking directory symlink {flysystemPath} realpath {targetAbsoluteFilesystemPath}",
                array_merge(
                    [ 'operation' => __METHOD__ ],
                    (array) $symlinkDetails
                )
            );
            $didRemove = $this->removeSymlink($path);

            if (!$didRemove) {
                throw UnableToDeleteFile::atLocation($path, 'symlink');
            }
            return;
        }

        switch ($this->linkHandling) {
            case LocalFilesystemAdapter::SKIP_LINKS:
                return;
            case LocalFilesystemAdapter::DISALLOW_LINKS:
                throw SymbolicLinkEncountered::atLocation($path);
            case SymlinkProtectFilesystemAdapter::WARN_LINKS:
                $this->logger->warning(
                    "Deleting directory {flysystemPath} under symlink {symlinkAbsoluteFilesystemPath} realpath {targetAbsoluteFilesystemPath}",
                    array_merge(
                        [ 'operation' => __METHOD__ ],
                        (array) $symlinkDetails
                    )
                );
                parent::deleteDirectory($path);

                return;
            case SymlinkProtectFilesystemAdapter::THROW_LINKS:
            default:
                throw UnableToDeleteDirectory::atLocation($path, 'symlink');
        }
    }

    /**
     * Recursively create a directory.
     * @throws FilesystemException
     */
    public function createDirectory(string $path, Config $config): void
    {
        $path = $this->normalizer->normalizePath($path);

        $existingParentDir = $path;
        do {
            $existingParentDir = dirname($existingParentDir);
        } while (!$this->directoryExists($existingParentDir));

        $symlinkDetails = $this->getSymlinkDetails($existingParentDir);
        $isSymlinked = !empty($symlinkDetails);

        if (!$isSymlinked) {
            parent::createDirectory($path, $config);
            return;
        }

        switch ($this->linkHandling) {
            case LocalFilesystemAdapter::SKIP_LINKS:
                return;
            case LocalFilesystemAdapter::DISALLOW_LINKS:
                throw SymbolicLinkEncountered::atLocation($path);
            case SymlinkProtectFilesystemAdapter::WARN_LINKS:
                $this->logger->warning(
                    "Creating directory under {flysystemPath} symlink at {symlinkAbsoluteFilesystemPath}",
                    array_merge(
                        [ 'operation' => __METHOD__ ],
                        (array) $symlinkDetails
                    )
                );
                parent::createDirectory($path, $config);
                return;
            case SymlinkProtectFilesystemAdapter::THROW_LINKS:
            default:
                throw UnableToCreateDirectory::atLocation($path, 'symlink');
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $source = $this->normalizer->normalizePath($source);
        $destination = $this->normalizer->normalizePath($destination);

        try {
            $sourceSymlinkDetails = $this->getSymlinkDetails($source);
        } catch (RuntimeException $exception) {
            // Source file not found.
            throw UnableToMoveFile::fromLocationTo($source, $destination, $exception);
        }
        $isSourceSymlinked = !empty($sourceSymlinkDetails);
        if ($isSourceSymlinked) {
            switch ($this->linkHandling) {
                case LocalFilesystemAdapter::SKIP_LINKS:
                    return;
                case LocalFilesystemAdapter::DISALLOW_LINKS:
                    throw SymbolicLinkEncountered::atLocation($source);
                case SymlinkProtectFilesystemAdapter::WARN_LINKS:
                    break;
                case SymlinkProtectFilesystemAdapter::THROW_LINKS:
                default:
                    throw UnableToMoveFile::fromLocationTo($source, $destination);
            }
        }

        $existingDestinationParentDir = $destination;
        do {
            $existingDestinationParentDir = dirname($existingDestinationParentDir);
        } while (!$this->directoryExists($existingDestinationParentDir));

        $destinationSymlinkDetails = $this->getSymlinkDetails($existingDestinationParentDir);
        $isDestinationSymlinked = !empty($destinationSymlinkDetails);

        if ($isDestinationSymlinked) {
            switch ($this->linkHandling) {
                case LocalFilesystemAdapter::SKIP_LINKS:
                    return;
                case LocalFilesystemAdapter::DISALLOW_LINKS:
                    throw SymbolicLinkEncountered::atLocation($destination);
                case SymlinkProtectFilesystemAdapter::WARN_LINKS:
                    break;
                case SymlinkProtectFilesystemAdapter::THROW_LINKS:
                default:
                    throw UnableToMoveFile::fromLocationTo($source, $destination);
            }
        }

        // TODO: improve this message.
        $logMessage = '';
        $logContext = [
            'source' => $source,
            'destination' => $destination,
        ];
        if ($isSourceSymlinked) {
            $logMessage .= 'Source symlinked. ';
            $logContext['sourceSymlinked'] = $sourceSymlinkDetails;
        }
        if ($isDestinationSymlinked) {
            $logMessage .= 'Destination symlinked.';
            $logContext['destinationSymlinked'] = $destinationSymlinkDetails;
        }
        if ($isSourceSymlinked || $isDestinationSymlinked) {
            $this->logger->warning(trim($logMessage), $logContext);
        }

        parent::move($source, $destination, $config);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $source = $this->normalizer->normalizePath($source);
        $destination = $this->normalizer->normalizePath($destination);

        try {
            $sourceSymlinkDetails = $this->getSymlinkDetails($source);
        } catch (RuntimeException $exception) {
            // Source file not found.
            throw UnableToCopyFile::fromLocationTo($source, $destination, $exception);
        }
        $isSourceSymlinked = !empty($sourceSymlinkDetails);
        if ($isSourceSymlinked) {
            switch ($this->linkHandling) {
                case LocalFilesystemAdapter::SKIP_LINKS:
                    return;
                case LocalFilesystemAdapter::DISALLOW_LINKS:
                    throw SymbolicLinkEncountered::atLocation($source);
                case SymlinkProtectFilesystemAdapter::WARN_LINKS:
                case SymlinkProtectFilesystemAdapter::THROW_LINKS:
                    // We do not throw here because THROW_LINKS only applies for write operations – it allows reading.
                default:
                    break;
            }
        }

        $existingDestinationParentDir = $destination;
        do {
            $existingDestinationParentDir = dirname($existingDestinationParentDir);
        } while (!$this->directoryExists($existingDestinationParentDir));

        $destinationSymlinkDetails = $this->getSymlinkDetails($existingDestinationParentDir);
        $isDestinationSymlinked = !empty($destinationSymlinkDetails);

        if (!$isDestinationSymlinked) {
            parent::copy($source, $destination, $config);
            return;
        }

        switch ($this->linkHandling) {
            case LocalFilesystemAdapter::SKIP_LINKS:
                return;
            case LocalFilesystemAdapter::DISALLOW_LINKS:
                throw SymbolicLinkEncountered::atLocation($destination);
            case SymlinkProtectFilesystemAdapter::WARN_LINKS:
                $this->logger->warning(
                    "Copying file to destination {destination} under symlink {flysystemPath} realpath {targetAbsoluteFilesystemPath}",
                    array_merge(
                        [
                            'destination' => $destination,
                            'operation' => __METHOD__
                        ],
                        (array) $destinationSymlinkDetails
                    )
                );
                parent::copy($source, $destination, $config);
                return;
            case SymlinkProtectFilesystemAdapter::THROW_LINKS:
            default:
                throw UnableToCopyFile::fromLocationTo($source, $destination);
        }
    }

    public function fileExists(string $path): bool
    {
        $path = $this->normalizer->normalizePath($path);

        // We run this so first encounter with a symlink is recorded.
        try {
            $this->getSymlinkDetails($path);
        } finally {
            return parent::fileExists($path);
        }
    }

    /**
     * @throws FilesystemException|UnableToReadFile
     */
    public function read(string $path): string
    {
        $path = $this->normalizer->normalizePath($path);

        try {
            // We run this so first encounter with a symlink is recorded.
            $this->getSymlinkDetails($path);
        } finally {
            return parent::read($path);
        }
    }

    /**
     * @throws FilesystemException|UnableToReadFile
     */
    public function readStream(string $path)
    {
        $path = $this->normalizer->normalizePath($path);

        try {
            // We run this so first encounter with a symlink is recorded.
            $this->getSymlinkDetails($path);
        } finally {
            return parent::readStream($path);
        }
    }

    public function visibility(string $path): FileAttributes
    {
        $path = $this->normalizer->normalizePath($path);

        try {
            // We run this so first encounter with a symlink is recorded.
            $this->getSymlinkDetails($path);
        } finally {
            return parent::visibility($path);
        }
    }

    public function mimeType(string $path): FileAttributes
    {
        $path = $this->normalizer->normalizePath($path);

        try {
            // We run this so first encounter with a symlink is recorded.
            $this->getSymlinkDetails($path);
        } finally {
            return parent::mimeType($path);
        }
    }

    public function lastModified(string $path): FileAttributes
    {
        $path = $this->normalizer->normalizePath($path);

        try {
            // We run this so first encounter with a symlink is recorded.
            $this->getSymlinkDetails($path);
        } finally {
            return parent::lastModified($path);
        }
    }

    public function fileSize(string $path): FileAttributes
    {
        $path = $this->normalizer->normalizePath($path);

        try {
            // We run this so first encounter with a symlink is recorded.
            $this->getSymlinkDetails($path);
        } finally {
            return parent::fileSize($path);
        }
    }

    /**
     * This is the only method in the parent class which uses {@see LocalFilesystemAdapter::$linkHandling} – we don't
     * use that because its results will not include files/directories that are symlinks.
     *
     * @see LocalFilesystemAdapter::listContents()
     *
     * @param string $path
     * @param bool $deep
     *
     * @return iterable
     * @throws FilesystemException
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $path = $this->normalizer->normalizePath($path);
        $location = $this->pathPrefixer->prefixPath($path);

        if (! is_dir($location)) {
            return;
        }

        /** @var SplFileInfo[] $iterator */
        $iterator = $deep ? $this->parentListDirectoryRecursively($location) : $this->parentListDirectory($location);

        foreach ($iterator as $fileInfo) {
            $symlinkDetails = $this->getSymlinkDetails($fileInfo->getPathname());
            $isSymlinked = !empty($symlinkDetails);

            if ($isSymlinked) {
                switch ($this->linkHandling) {
                    case LocalFilesystemAdapter::SKIP_LINKS:
                        continue 2;
                    case LocalFilesystemAdapter::DISALLOW_LINKS:
                        throw SymbolicLinkEncountered::atLocation($fileInfo->getPathname());
                    case SymlinkProtectFilesystemAdapter::WARN_LINKS:
                    case SymlinkProtectFilesystemAdapter::THROW_LINKS:
                    default:
                        // We are not warning or throwing here because we are just reading.
                        break;
                }
            }

            $path = $this->pathPrefixer->stripPrefix($fileInfo->getPathname());
            $lastModified = $fileInfo->getMTime();
            $isDirectory = $fileInfo->isDir();
            $permissions = octdec(substr(sprintf('%o', $fileInfo->getPerms()), -4));
            $visibility = $isDirectory ? $this->visibility->inverseForDirectory($permissions) : $this->visibility->inverseForFile($permissions);

            yield $isDirectory ? new DirectoryAttributes($path, $visibility, $lastModified) : new FileAttributes(
                str_replace('\\', '/', $path),
                $fileInfo->getSize(),
                $visibility,
                $lastModified
            );
        }
    }

    /**
     * (fn () => $this->protectedProperty)-›call ($object)
     *
     * Copied, unchanged, from parent due to private protection.
     * @used-by ::listContents
     * @see LocalFilesystemAdapter::listDirectoryRecursively()
     */
    private function parentListDirectoryRecursively(
        string $path,
        int $mode = RecursiveIteratorIterator::SELF_FIRST
    ): Generator {
        yield from new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            $mode
        );
    }

    /**
     * Copied, unchanged, from parent due to private protection.
     * @used-by ::listContents
     * @see LocalFilesystemAdapter::listDirectory()
     */
    private function parentListDirectory(string $location): Generator
    {
        $iterator = new DirectoryIterator($location);

        foreach ($iterator as $item) {
            if ($item->isDot()) {
                continue;
            }

            yield $item;
        }
    }
}
