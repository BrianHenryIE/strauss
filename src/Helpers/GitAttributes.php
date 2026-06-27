<?php
/**
 * Minimal `.gitattributes` parser, used to determine which files a package marks `export-ignore`
 * (i.e. files `git archive` / Composer dist would strip from the distributed package).
 *
 * Only the subset of `.gitattributes` needed by Strauss is implemented: line parsing into
 * pattern + attributes, and `export-ignore` path matching using gitignore-style globbing.
 *
 * @author Claude
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Helpers;

use League\Flysystem\FilesystemException;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;

class GitAttributes
{
    protected FileSystem $filesystem;

    protected string $repositoryPath;

    protected string $gitAttributesFilename;

    /**
     * @var ?array<array{pattern:string, attributes:array<string, bool|string|null>}>
     */
    protected ?array $parsed = null;

    public function __construct(
        FileSystem $filesystem,
        string $repositoryPath,
        string $gitAttributesFilename = '.gitattributes'
    ) {
        $this->filesystem = $filesystem;
        $this->repositoryPath = rtrim(FileSystem::normalizeDirSeparator($repositoryPath), '/');
        $this->gitAttributesFilename = $gitAttributesFilename;
    }

    /**
     * Read and parse the repository's `.gitattributes` file.
     *
     * Each returned entry is the pattern and its attributes, where an attribute is:
     *  - `true`   for a set attribute, e.g. `export-ignore`
     *  - `false`  for an unset attribute, e.g. `-export-ignore`
     *  - `null`   for an unspecified attribute, e.g. `!export-ignore`
     *  - `string` for a valued attribute, e.g. `eol=lf`
     *
     * @return array<array{pattern:string, attributes:array<string, bool|string|null>}>
     * @throws FilesystemException
     */
    public function parse(): array
    {
        if ($this->parsed !== null) {
            return $this->parsed;
        }

        $this->parsed = [];

        $gitAttributesPath = $this->repositoryPath . '/' . $this->gitAttributesFilename;

        if (!$this->filesystem->fileExists($gitAttributesPath)) {
            return $this->parsed;
        }

        $contents = $this->filesystem->read($gitAttributesPath);

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            // Skip blank lines and comments.
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            $tokens = preg_split('/\s+/', $line) ?: [];
            $pattern = array_shift($tokens);

            if ($pattern === null || $pattern === '') {
                continue;
            }

            $attributes = [];
            foreach ($tokens as $token) {
                if (strpos($token, '-') === 0) {
                    $attributes[substr($token, 1)] = false;
                } elseif (strpos($token, '!') === 0) {
                    $attributes[substr($token, 1)] = null;
                } elseif (strpos($token, '=') !== false) {
                    [$name, $value] = explode('=', $token, 2);
                    $attributes[$name] = $value;
                } else {
                    $attributes[$token] = true;
                }
            }

            $this->parsed[] = [
                'pattern' => $pattern,
                'attributes' => $attributes,
            ];
        }

        return $this->parsed;
    }

    /**
     * Whether the given repository-relative path is marked `export-ignore`.
     *
     * The last matching pattern wins, so a later `-export-ignore` rule can re-include a path.
     *
     * @throws FilesystemException
     */
    public function isExportIgnored(string $relativePath): bool
    {
        $relativePath = ltrim(FileSystem::normalizeDirSeparator($relativePath), '/');

        $ignored = false;

        foreach ($this->parse() as $entry) {
            if (!array_key_exists('export-ignore', $entry['attributes'])) {
                continue;
            }

            if ($this->matchesPattern($entry['pattern'], $relativePath)) {
                $ignored = $entry['attributes']['export-ignore'] === true;
            }
        }

        return $ignored;
    }

    /**
     * Match a `.gitattributes`/`.gitignore`-style pattern against a repository-relative file path.
     *
     * A pattern matches when it matches the path itself or one of its ancestor directories
     * (marking a directory `export-ignore` also excludes its contents).
     */
    protected function matchesPattern(string $pattern, string $relativePath): bool
    {
        $pattern = rtrim(FileSystem::normalizeDirSeparator($pattern), '/');
        // A pattern containing a slash (other than a trailing one) is anchored to the repository root.
        $isAnchored = strpos($pattern, '/') !== false;
        $pattern = ltrim($pattern, '/');

        if ($pattern === '') {
            return false;
        }

        if (!$isAnchored) {
            // An unanchored pattern matches any single path segment, e.g. `tests` or `*.dist`.
            foreach (explode('/', $relativePath) as $segment) {
                if (fnmatch($pattern, $segment)) {
                    return true;
                }
            }
            return false;
        }

        if (fnmatch($pattern, $relativePath, FNM_PATHNAME)) {
            return true;
        }

        // Match against each ancestor directory so a directory pattern covers the files within it.
        $segments = explode('/', $relativePath);
        array_pop($segments);

        $ancestor = '';
        foreach ($segments as $segment) {
            $ancestor = $ancestor === '' ? $segment : $ancestor . '/' . $segment;
            if (fnmatch($pattern, $ancestor, FNM_PATHNAME)) {
                return true;
            }
        }

        return false;
    }
}
