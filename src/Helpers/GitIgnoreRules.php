<?php
/**
 * Decides whether a path in a repository is ignored by the `.gitignore` files above it.
 *
 * A replacement for `inmarelibero/gitignore-checker` which gives the same answers, including where those differ
 * from Git's own, so the files Strauss copies do not change. That library re-reads and re-parses every
 * `.gitignore` above a path, and rebuilds every rule's regex, for each path it is asked about. Here each
 * `.gitignore` is read and compiled once per repository, and the result of matching a rule against a path
 * segment is remembered: a project's paths are made of a small number of distinct segments.
 *
 * How a path is decided:
 *
 * - The `.gitignore` in each directory above the path is consulted, from the repository root down. The first
 *   which ignores the path decides; a deeper file cannot un-ignore it.
 * - Within a file, the path is ignored when a rule matches it and no later `!` rule also matches it.
 * - Rules are matched segment by segment, case-insensitively, against the path from the repository root (even
 *   for a nested `.gitignore`). `*` matches any run of characters; a leading `/` requires the match to be the
 *   first segment; a trailing `/` requires it not to be the path's final segment unless the path is a directory.
 *
 * @see \BrianHenryIE\Strauss\Pipeline\FileEnumerator::excludeGitFiles()
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Helpers;

use RuntimeException;

class GitIgnoreRules
{
    protected const GITIGNORE_FILENAME = '.gitignore';

    /**
     * The repository root, as `realpath()` gives it.
     */
    protected string $repositoryPath;

    /**
     * The compiled rules of the `.gitignore` in each directory asked about so far, keyed by the directory's path
     * relative to the repository root. Null when the directory has none.
     *
     * @var array<string, ?array<array{index:int, isNegated:bool, isComplex:bool, tokens:string[], regexes:string[], hasInitialSlash:bool, hasTrailingSlash:bool}>>
     */
    protected array $rulesByDirectory = [];

    /**
     * Whether a rule's regex matches a path segment, keyed by regex then by segment.
     *
     * @var array<string, array<string, bool>>
     */
    protected array $segmentMatches = [];

    /**
     * @param string $repositoryPath Absolute path of the repository root.
     *
     * @throws RuntimeException When the path is not a readable directory on the local filesystem.
     */
    public function __construct(string $repositoryPath)
    {
        if (!file_exists($repositoryPath)) {
            throw new RuntimeException(sprintf('Path "%s" does not exist.', $repositoryPath));
        }

        $realPath = realpath($repositoryPath);

        if (!is_dir($repositoryPath) || !is_readable($repositoryPath) || false === $realPath) {
            throw new RuntimeException(sprintf('Unable to set path "%s".', $repositoryPath));
        }

        $this->repositoryPath = $realPath;
    }

    /**
     * @param string $path The path to check, relative to the repository root and beginning with `/`.
     *
     * @throws RuntimeException When the path does not exist or cannot be read.
     */
    public function isPathIgnored(string $path): bool
    {
        $absolutePath = $this->getAbsolutePath($path);

        if (!file_exists($absolutePath)) {
            throw new RuntimeException(sprintf('Path "%s" does not exist.', $absolutePath));
        }
        if (!is_readable($absolutePath) || false === realpath($absolutePath)) {
            throw new RuntimeException(sprintf('Unable to set path "%s".', $path));
        }

        $pathTokens = $this->getTokens($path);

        // A directory is matched with a trailing slash, which is what a rule's trailing slash looks for.
        $isDirectory = is_dir($absolutePath);

        // Each directory above the path, from the repository root down.
        $tokenCount = count($pathTokens);
        for ($depth = 0; $depth < $tokenCount; $depth++) {
            $directory = '/' . implode('/', array_slice($pathTokens, 0, $depth));

            foreach (array_reverse($this->getRules($directory)) as $rule) {
                if ($rule['isNegated']) {
                    continue;
                }
                if (!$this->isRuleMatched($rule, $pathTokens, $isDirectory)) {
                    continue;
                }
                // The last ignoring rule which matches. It stands unless a later `!` rule matches too.
                if (!$this->isNegatedAfter($rule['index'], $this->getRules($directory), $pathTokens, $isDirectory)) {
                    return true;
                }
                break;
            }
        }

        return false;
    }

    /**
     * Does a `!` rule after the given index match the path?
     *
     * @param array<array{index:int, isNegated:bool, isComplex:bool, tokens:string[], regexes:string[], hasInitialSlash:bool, hasTrailingSlash:bool}> $rules
     * @param string[] $pathTokens
     */
    protected function isNegatedAfter(int $index, array $rules, array $pathTokens, bool $isDirectory): bool
    {
        foreach (array_reverse($rules) as $rule) {
            if ($rule['index'] <= $index) {
                return false;
            }
            if ($rule['isNegated'] && $this->isRuleMatched($rule, $pathTokens, $isDirectory)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array{index:int, isNegated:bool, isComplex:bool, tokens:string[], regexes:string[], hasInitialSlash:bool, hasTrailingSlash:bool} $rule
     * @param string[] $pathTokens
     */
    protected function isRuleMatched(array $rule, array $pathTokens, bool $isDirectory): bool
    {
        // The first path segment each of the rule's segments matches.
        $firstMatches = [];
        foreach ($rule['regexes'] as $position => $regex) {
            $firstMatch = $this->getFirstMatch($regex, $pathTokens);
            if (is_null($firstMatch)) {
                return false;
            }
            $firstMatches[$position] = $firstMatch;
        }

        if ($rule['isComplex']) {
            // Each later segment of the rule, other than a wildcard, must first match after the rule's first
            // segment does.
            foreach ($rule['tokens'] as $position => $token) {
                if (0 === $position || '*' === $token || '**' === $token) {
                    continue;
                }
                if ($firstMatches[$position] <= $firstMatches[0]) {
                    return false;
                }
            }
        }

        if (empty($firstMatches)) {
            return true;
        }

        if ($rule['hasInitialSlash'] && 0 !== $firstMatches[0]) {
            return false;
        }

        if ($rule['hasTrailingSlash']
            && !$isDirectory
            && $firstMatches[count($firstMatches) - 1] === count($pathTokens) - 1
        ) {
            return false;
        }

        return true;
    }

    /**
     * The index of the first path segment the regex matches.
     *
     * @param string[] $pathTokens
     */
    protected function getFirstMatch(string $regex, array $pathTokens): ?int
    {
        foreach ($pathTokens as $index => $token) {
            if ($this->segmentMatches[$regex][$token] ??= (1 === preg_match($regex, $token))) {
                return $index;
            }
        }
        return null;
    }

    /**
     * The compiled rules of the `.gitignore` in a directory, in file order.
     *
     * @param string $directory Relative to the repository root, beginning with `/`.
     *
     * @return array<array{index:int, isNegated:bool, isComplex:bool, tokens:string[], regexes:string[], hasInitialSlash:bool, hasTrailingSlash:bool}>
     */
    protected function getRules(string $directory): array
    {
        if (!array_key_exists($directory, $this->rulesByDirectory)) {
            $this->rulesByDirectory[$directory] = $this->readRules($directory);
        }

        return $this->rulesByDirectory[$directory] ?? [];
    }

    /**
     * @return ?array<array{index:int, isNegated:bool, isComplex:bool, tokens:string[], regexes:string[], hasInitialSlash:bool, hasTrailingSlash:bool}>
     */
    protected function readRules(string $directory): ?array
    {
        if (self::GITIGNORE_FILENAME === substr($directory, -strlen(self::GITIGNORE_FILENAME))) {
            throw new RuntimeException(sprintf(
                'The path must not end with %s: "%s" given.',
                self::GITIGNORE_FILENAME,
                $directory
            ));
        }

        $gitIgnorePath = $this->getAbsolutePath($directory . '/' . self::GITIGNORE_FILENAME);

        if (!file_exists($gitIgnorePath)) {
            return null;
        }

        if (!is_readable($gitIgnorePath)) {
            throw new RuntimeException(sprintf('"%s" is not readable.', $gitIgnorePath));
        }

        $content = file_get_contents($gitIgnorePath);

        return false === $content ? null : $this->parse($content);
    }

    /**
     * @return array<array{index:int, isNegated:bool, isComplex:bool, tokens:string[], regexes:string[], hasInitialSlash:bool, hasTrailingSlash:bool}>
     */
    protected function parse(string $content): array
    {
        // Blank lines are dropped before the lines are numbered; comments are numbered but are not rules.
        $lines = array_values(array_filter(array_map('trim', explode(PHP_EOL, $content))));

        $rules = [];
        foreach ($lines as $index => $line) {
            if (0 === strpos($line, '#')) {
                continue;
            }

            $isNegated = 0 === strpos($line, '!');
            $pattern = $isNegated ? substr($line, 1) : $line;

            // A slash with something on both sides: the rule names a path, not just one segment.
            $isComplex = 1 === preg_match('#.+\/.+#i', $pattern);

            $tokens = $isComplex ? $this->getTokens($pattern) : [$pattern];

            $rules[] = [
                'index' => $index,
                'isNegated' => $isNegated,
                'isComplex' => $isComplex,
                'tokens' => $tokens,
                'regexes' => array_map([$this, 'compile'], $tokens),
                'hasInitialSlash' => 0 === strpos($pattern, '/'),
                'hasTrailingSlash' => '/' === substr($pattern, -1),
            ];
        }

        return $rules;
    }

    /**
     * The regex for one segment of a rule: `.` is literal, `*` matches anything, and a leading or trailing slash
     * is not part of the name. Anything else in the rule is left as regex syntax.
     */
    protected function compile(string $ruleToken): string
    {
        $regex = str_replace('.', '\.', $ruleToken);
        $regex = str_replace('*', '.*', $regex);

        if (0 === strpos($regex, '/')) {
            $regex = substr($regex, 1);
        }
        if ('/' === substr($regex, -1)) {
            $regex = substr($regex, 0, -1);
        }

        // Escape the delimiter, unless it is already escaped.
        $regex = (string) preg_replace('@(?<!\\\\)#@', '\#', '^' . $regex . '$');

        return '#' . $regex . '#i';
    }

    /**
     * The segments of a path. As well as empty segments, a segment named `0` is dropped.
     *
     * @return string[]
     */
    protected function getTokens(string $path): array
    {
        return array_values(array_filter(explode('/', $path)));
    }

    protected function getAbsolutePath(string $path): string
    {
        return (string) preg_replace('#//#', '/', $this->repositoryPath . '/' . $path);
    }
}
