<?php
/**
 * Keeps each file's {@see FileCodeMap} between runs, so a file whose contents have not changed is not parsed again.
 *
 * A map is determined by the file's contents alone, never by its path or its project, so entries are keyed by a
 * hash of the contents and are shared by every project on the machine: a package installed in two projects is
 * parsed once.
 *
 * Entries are only valid for the code that wrote them. They are stored under a fingerprint of everything which
 * decides what a map contains ({@see self::getFingerprint()}); a different Strauss, php-parser or PHP version
 * reads and writes a different directory, and removes those which have not been used for a while.
 *
 * The cache is an optimisation: an entry which is missing, unreadable or corrupt is a miss, and a cache which
 * cannot be written to is not written to.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline\FileSymbol;

use BrianHenryIE\Strauss\Files\CodeLocation;
use BrianHenryIE\Strauss\Files\FileCodeMap;
use FilesystemIterator;
use PhpParser\ParserFactory;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Throwable;

class AnalysisCache
{
    use LoggerAwareTrait;

    /**
     * Another fingerprint's directory is removed when no run has used it for this long.
     */
    protected const UNUSED_SECONDS = 30 * 24 * 60 * 60;

    /**
     * The classes whose source decides what is in a map.
     */
    protected const FINGERPRINT_CLASSES = [
        PhpFileAnalyzer::class,
        FileCodeMapVisitor::class,
        FileCodeMap::class,
        CodeLocation::class,
        SymbolDefinition::class,
    ];

    /**
     * Contains one directory per fingerprint.
     */
    protected string $directory;

    /**
     * Read entries but never write or remove any, e.g. during `--dry-run`.
     */
    protected bool $readOnly;

    protected ?string $fingerprint = null;

    /**
     * Has the fingerprint's directory been created (and the others removed) during this run?
     */
    protected bool $isPrepared = false;

    protected ?bool $hasEntries = null;

    protected int $hits = 0;

    protected int $misses = 0;

    public function __construct(string $directory, bool $readOnly = false, ?LoggerInterface $logger = null)
    {
        $this->directory = rtrim($directory, '/\\');
        $this->readOnly = $readOnly;
        $this->logger = $logger ?? new NullLogger();
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    /**
     * Was there anything to read when this run started? When not (the first run, or a CI job without a persisted
     * cache), looking each file up would only cost time.
     */
    public function hasEntries(): bool
    {
        return $this->hasEntries ??= is_dir($this->directory . '/' . $this->getFingerprint());
    }

    /**
     * The key of the entry for a file with these contents.
     */
    public function getKey(string $contents): string
    {
        return sha1($contents);
    }

    /**
     * The result of analysing these contents on an earlier run: the map, or null and the parse error message when
     * they could not be parsed.
     *
     * @return ?array{0:?FileCodeMap, 1:?string} Null when there is no (valid) entry.
     */
    public function get(string $contents): ?array
    {
        $hasEntries = $this->hasEntries();

        // A run which only reads still marks the cache as in use.
        if (!$this->readOnly) {
            $this->prepare();
        }

        $result = $hasEntries ? $this->read($this->getKey($contents)) : null;

        // Guards against a truncated or damaged entry, and a hash collision.
        if (!is_null($result) && !is_null($result[0]) && !$result[0]->matchesContent($contents)) {
            $result = null;
        }

        is_null($result) ? $this->misses++ : $this->hits++;

        return $result;
    }

    /**
     * Record the result of analysing these contents.
     *
     * @param ?FileCodeMap $codeMap Null when the contents could not be parsed.
     * @param ?string $errorMessage The parse error.
     */
    public function set(string $contents, ?FileCodeMap $codeMap, ?string $errorMessage = null): void
    {
        $this->setByKey($this->getKey($contents), $codeMap, $errorMessage);
    }

    /**
     * Record the result of analysing the contents whose key is {@see self::getKey()}.
     */
    public function setByKey(string $key, ?FileCodeMap $codeMap, ?string $errorMessage = null): void
    {
        if ($this->readOnly || !$this->isKey($key) || !$this->prepare()) {
            return;
        }

        $path = $this->getPath($key);
        $entryDirectory = dirname($path);

        if (!is_dir($entryDirectory) && !@mkdir($entryDirectory, 0777, true) && !is_dir($entryDirectory)) {
            $this->stopWriting($entryDirectory);
            return;
        }

        // Written beside the entry and renamed into place, so another process never reads half an entry.
        $temporaryPath = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (false === @file_put_contents($temporaryPath, $this->encode([$codeMap, $errorMessage]))
            || !@rename($temporaryPath, $path)
        ) {
            @unlink($temporaryPath);
            $this->stopWriting($path);
            return;
        }

        $this->hasEntries = true;
    }

    public function getHits(): int
    {
        return $this->hits;
    }

    public function getMisses(): int
    {
        return $this->misses;
    }

    /**
     * Identifies the code which produces maps: the PHP version (its tokenizer is used), the php-parser in use, and
     * the source of the analyser and of the classes a map is made of.
     *
     * Derived from the source files rather than a version number so it is also right for a development checkout.
     */
    public function getFingerprint(): string
    {
        if (is_null($this->fingerprint)) {
            $parts = [PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $this->isCompressed() ? 'deflate' : 'plain'];

            foreach (array_merge(self::FINGERPRINT_CLASSES, [ParserFactory::class]) as $class) {
                $file = (new ReflectionClass($class))->getFileName();
                $parts[] = $class . ':' . (false === $file ? '' : sha1((string) @file_get_contents($file)));
            }
            $parts[] = $this->getParserVersion();

            $this->fingerprint = sha1(implode('|', $parts));
        }

        return $this->fingerprint;
    }

    /**
     * When run via `strauss.phar`, Composer's classes are prefixed.
     */
    protected function getParserVersion(): string
    {
        try {
            $version = class_exists(\BrianHenryIE\Strauss\Composer\InstalledVersions::class)
                ? \BrianHenryIE\Strauss\Composer\InstalledVersions::getReference('nikic/php-parser')
                : \Composer\InstalledVersions::getReference('nikic/php-parser');
        } catch (Throwable $e) {
            $version = null;
        }

        return is_string($version) ? $version : 'unknown';
    }

    /**
     * A serialized map is many times the size of the file it describes, and compresses to a fraction of it.
     */
    protected function isCompressed(): bool
    {
        return function_exists('gzdeflate') && function_exists('gzinflate');
    }

    /**
     * @param array{0:?FileCodeMap, 1:?string} $result
     */
    protected function encode(array $result): string
    {
        $serialized = serialize($result);

        if (!$this->isCompressed()) {
            return $serialized;
        }

        $compressed = gzdeflate($serialized, 1);

        return false === $compressed ? '' : $compressed;
    }

    protected function isKey(string $key): bool
    {
        return 1 === preg_match('/^[0-9a-f]{40}$/', $key);
    }

    protected function getPath(string $key): string
    {
        return $this->directory . '/' . $this->getFingerprint() . '/' . substr($key, 0, 2) . '/' . $key;
    }

    /**
     * @return ?array{0:?FileCodeMap, 1:?string}
     */
    protected function read(string $key): ?array
    {
        $encoded = @file_get_contents($this->getPath($key));

        if (false === $encoded) {
            return null;
        }

        $serialized = $this->isCompressed() ? @gzinflate($encoded) : $encoded;

        if (false === $serialized) {
            return null;
        }

        $result = @unserialize($serialized, [
            'allowed_classes' => [FileCodeMap::class, CodeLocation::class, SymbolDefinition::class],
        ]);

        if (!is_array($result)
            || 2 !== count($result)
            || !array_key_exists(0, $result)
            || !array_key_exists(1, $result)
            || !(is_null($result[0]) || $result[0] instanceof FileCodeMap)
            || !(is_null($result[1]) || is_string($result[1]))
        ) {
            return null;
        }

        return [$result[0], $result[1]];
    }

    /**
     * Create this fingerprint's directory, mark it as used now, and remove the directories of other fingerprints
     * which have not been used for {@see self::UNUSED_SECONDS}: the code which wrote them has probably been replaced.
     *
     * Returns whether entries can be written.
     */
    protected function prepare(): bool
    {
        if ($this->isPrepared) {
            return !$this->readOnly;
        }
        $this->isPrepared = true;

        $fingerprintDirectory = $this->directory . '/' . $this->getFingerprint();

        if (!is_dir($fingerprintDirectory)
            && !@mkdir($fingerprintDirectory, 0777, true)
            && !is_dir($fingerprintDirectory)
        ) {
            $this->stopWriting($fingerprintDirectory);
            return false;
        }

        @touch($fingerprintDirectory);

        foreach (@scandir($this->directory) ?: [] as $name) {
            $otherDirectory = $this->directory . '/' . $name;
            if ($name === $this->getFingerprint() || !$this->isKey($name)) {
                continue;
            }
            $lastUsed = @filemtime($otherDirectory);
            if (false === $lastUsed || $lastUsed > time() - self::UNUSED_SECONDS) {
                continue;
            }
            $this->logger->debug('Removing unused analysis cache: {directory}', ['directory' => $otherDirectory]);
            $this->removeDirectory($otherDirectory);
        }

        return true;
    }

    protected function stopWriting(string $path): void
    {
        if (!$this->readOnly) {
            $this->logger->debug('Analysis cache is not writable, continuing without it: {path}', ['path' => $path]);
        }
        $this->readOnly = true;
    }

    /**
     * Only ever called with a directory named by a fingerprint, inside the cache's own directory.
     */
    protected function removeDirectory(string $directory): void
    {
        if (is_link($directory) || !is_dir($directory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var \SplFileInfo $entry */
        foreach ($entries as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($directory);
    }
}
