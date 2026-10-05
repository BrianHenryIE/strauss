<?php
/**
 * {@see GitIgnoreRules} must give the answers `inmarelibero/gitignore-checker` gives, which it replaced, so the
 * files Strauss copies do not change. The library is kept as a dev dependency to check against.
 *
 * Builds a repository in the system's temporary directory, which is removed after each test.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Unit\Helpers;

use BrianHenryIE\Strauss\Helpers\GitIgnoreRules;
use BrianHenryIE\Strauss\TestCase;
use FilesystemIterator;
use Inmarelibero\GitIgnoreChecker\GitIgnoreChecker;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Helpers\GitIgnoreRules
 */
class GitIgnoreRulesTest extends TestCase
{
    protected string $repositoryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repositoryPath = sys_get_temp_dir() . '/strauss-gitignore-rules-test-' . bin2hex(random_bytes(6));
        mkdir($this->repositoryPath, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->repositoryPath)) {
            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->repositoryPath, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            /** @var \SplFileInfo $entry */
            foreach ($entries as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->repositoryPath);
        }

        parent::tearDown();
    }

    /**
     * Create the files, and their directories, in the repository. A path ending in `/` is an empty directory.
     *
     * @param array<string, string>|string[] $files Paths relative to the repository, or path => contents.
     */
    protected function createFiles(array $files): void
    {
        foreach ($files as $key => $value) {
            [$path, $contents] = is_int($key) ? [$value, ''] : [$key, $value];
            $absolutePath = $this->repositoryPath . '/' . ltrim($path, '/');
            if ('/' === substr($path, -1)) {
                is_dir($absolutePath) || mkdir($absolutePath, 0777, true);
                continue;
            }
            is_dir(dirname($absolutePath)) || mkdir(dirname($absolutePath), 0777, true);
            file_put_contents($absolutePath, $contents);
        }
    }

    /**
     * Every file and directory in the repository, relative to it, beginning with `/`.
     *
     * @return string[]
     */
    protected function getAllPaths(): array
    {
        $paths = [];
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->repositoryPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        /** @var \SplFileInfo $entry */
        foreach ($entries as $entry) {
            $paths[] = substr($entry->getPathname(), strlen($this->repositoryPath));
        }
        sort($paths);
        return $paths;
    }

    /**
     * The paths the class ignores, after checking that the library ignores exactly the same ones.
     *
     * @return string[]
     */
    protected function getIgnoredPaths(): array
    {
        $sut = new GitIgnoreRules($this->repositoryPath);
        $library = new GitIgnoreChecker($this->repositoryPath);

        $ignored = [];
        $ignoredByLibrary = [];
        foreach ($this->getAllPaths() as $path) {
            if ($sut->isPathIgnored($path)) {
                $ignored[] = $path;
            }
            if ($library->isPathIgnored($path)) {
                $ignoredByLibrary[] = $path;
            }
        }

        self::assertSame($ignoredByLibrary, $ignored, 'GitIgnoreRules and gitignore-checker disagree.');

        return $ignored;
    }

    /**
     * A name ignores the file or directory of that name at any depth, and everything inside the directory.
     *
     * @covers ::isPathIgnored
     * @covers ::isRuleMatched
     */
    public function test_a_name_matches_at_any_depth(): void
    {
        $this->createFiles([
            '.gitignore' => "node_modules\n",
            'node_modules/package/index.js',
            'src/node_modules/other.js',
            'src/Kept.php',
            'node_modules_not/Kept.php',
        ]);

        self::assertSame(
            [
                '/node_modules',
                '/node_modules/package',
                '/node_modules/package/index.js',
                '/src/node_modules',
                '/src/node_modules/other.js',
            ],
            $this->getIgnoredPaths()
        );
    }

    /**
     * @covers ::isPathIgnored
     * @covers ::compile
     */
    public function test_wildcards_and_literal_dots(): void
    {
        $this->createFiles([
            '.gitignore' => "*.log\n.env\nbuild-*\n",
            'debug.log',
            'src/nested/error.log',
            'src/logger.php',
            'src/xlog',
            '.env',
            'aenv',
            'build-output/file.txt',
            'rebuild-x/file.txt',
        ]);

        self::assertSame(
            [
                '/.env',
                '/build-output',
                '/build-output/file.txt',
                '/debug.log',
                '/src/nested/error.log',
            ],
            $this->getIgnoredPaths()
        );
    }

    /**
     * `dir/` ignores a directory and its contents, but not a file of that name.
     *
     * @covers ::isRuleMatched
     */
    public function test_trailing_slash_only_matches_directories(): void
    {
        $this->createFiles([
            '.gitignore' => "cache/\n",
            'cache/data.bin',
            'src/cache/data.bin',
            'docs/cache',
        ]);

        self::assertSame(
            [
                '/cache',
                '/cache/data.bin',
                '/src/cache',
                '/src/cache/data.bin',
            ],
            $this->getIgnoredPaths()
        );
    }

    /**
     * `/x` only matches at the root of the repository.
     *
     * @covers ::isRuleMatched
     */
    public function test_leading_slash_anchors_to_the_root(): void
    {
        $this->createFiles([
            '.gitignore' => "/vendor\n/composer.lock\n",
            'vendor/package/file.php',
            'src/vendor/file.php',
            'composer.lock',
            'tests/composer.lock',
        ]);

        self::assertSame(
            [
                '/composer.lock',
                '/vendor',
                '/vendor/package',
                '/vendor/package/file.php',
            ],
            $this->getIgnoredPaths()
        );
    }

    /**
     * A later `!` rule which matches the path un-ignores it; an earlier one does not.
     *
     * @covers ::isPathIgnored
     * @covers ::isNegatedAfter
     */
    public function test_negation(): void
    {
        $this->createFiles([
            '.gitignore' => "!early.log\n*.log\n!keep.log\n",
            'debug.log',
            'keep.log',
            'early.log',
            'src/keep.log',
            'src/other.log',
        ]);

        self::assertSame(
            [
                '/debug.log',
                '/early.log',
                '/src/other.log',
            ],
            $this->getIgnoredPaths()
        );
    }

    /**
     * A rule with a slash inside it names a path: its segments must appear in order.
     *
     * @covers ::isRuleMatched
     * @covers ::parse
     */
    public function test_rules_naming_a_path(): void
    {
        $this->createFiles([
            '.gitignore' => "docs/build\n/tests/_output/\nassets/**/*.map\n",
            'docs/build/index.html',
            'docs/source/index.md',
            'build/docs/readme.md',
            'tests/_output/report.xml',
            'src/tests/_output/report.xml',
            'assets/js/dist/app.js.map',
            'assets/js/app.js',
            'other/app.js.map',
        ]);

        self::assertSame(
            [
                '/assets/js/dist/app.js.map',
                '/docs/build',
                '/docs/build/index.html',
                '/tests/_output',
                '/tests/_output/report.xml',
            ],
            $this->getIgnoredPaths()
        );
    }

    /**
     * A `.gitignore` in a subdirectory applies to the paths below it. What the root ignores stays ignored.
     *
     * @covers ::isPathIgnored
     * @covers ::getRules
     * @covers ::readRules
     */
    public function test_nested_gitignore(): void
    {
        $this->createFiles([
            '.gitignore' => "*.tmp\n",
            'packages/one/.gitignore' => "generated\n!important.tmp\n",
            'packages/one/generated/file.php',
            'packages/one/src/File.php',
            'packages/one/important.tmp',
            'packages/two/generated/file.php',
            'root.tmp',
        ]);

        self::assertSame(
            [
                '/packages/one/generated',
                '/packages/one/generated/file.php',
                '/packages/one/important.tmp',
                '/root.tmp',
            ],
            $this->getIgnoredPaths()
        );
    }

    /**
     * @covers ::parse
     */
    public function test_comments_blank_lines_whitespace_and_windows_line_endings(): void
    {
        $this->createFiles([
            '.gitignore' => "# A comment\r\n\r\n   dist   \r\n#ignored.txt\r\n\n\ncoverage\n",
            'dist/app.js',
            'coverage/index.html',
            'ignored.txt',
            'src/File.php',
        ]);

        self::assertSame(
            [
                '/coverage',
                '/coverage/index.html',
                '/dist',
                '/dist/app.js',
            ],
            $this->getIgnoredPaths()
        );
    }

    /**
     * Matching is case-insensitive, as the library's is.
     *
     * @covers ::compile
     */
    public function test_matching_is_case_insensitive(): void
    {
        $this->createFiles([
            '.gitignore' => "Thumbs.db\n",
            'images/thumbs.DB',
            'images/photo.jpg',
        ]);

        self::assertSame(['/images/thumbs.DB'], $this->getIgnoredPaths());
    }

    /**
     * @covers ::isPathIgnored
     */
    public function test_nothing_is_ignored_without_a_gitignore(): void
    {
        $this->createFiles(['src/File.php', 'vendor/package/file.php', 'empty/']);

        self::assertSame([], $this->getIgnoredPaths());
    }

    /**
     * A mix of rule kinds over a larger tree: only the comparison with the library is asserted.
     *
     * @covers ::isPathIgnored
     */
    public function test_agrees_with_the_library_over_a_mixed_tree(): void
    {
        $files = [
            '.gitignore' => implode("\n", [
                '# Dependencies',
                '/vendor/',
                'node_modules',
                '*.log',
                '!keep.log',
                '.idea/',
                'build/**',
                '/tests/_reports',
                'docs/*.pdf',
                '.DS_Store',
                '*.min.*',
                '!jquery.min.js',
                'tmp*',
                '/.phpunit.result.cache',
                '',
            ]),
            'packages/a/.gitignore' => "/dist\n*.cache\n!.gitkeep\n",
            'packages/a/b/.gitignore' => "secret\n",
        ];
        foreach (['', 'src/', 'src/deep/er/', 'packages/a/', 'packages/a/b/', 'vendor/acme/lib/', 'tests/', 'docs/'] as $dir) {
            foreach ([
                'File.php', 'debug.log', 'keep.log', 'app.min.js', 'jquery.min.js', 'style.min.css', '.DS_Store',
                'manual.pdf', 'tmpfile', 'data.cache', '.gitkeep', 'secret', 'dist/out.js', 'build/x/y.o',
                'node_modules/m/index.js', '.idea/workspace.xml', '_reports/report.xml', '.phpunit.result.cache',
            ] as $file) {
                $files[] = $dir . $file;
            }
        }
        $this->createFiles($files);

        $ignored = $this->getIgnoredPaths();

        self::assertNotEmpty($ignored);
        self::assertContains('/vendor', $ignored);
        self::assertNotContains('/src/File.php', $ignored);
        self::assertLessThan(count($this->getAllPaths()), count($ignored));
    }

    /**
     * Each `.gitignore` is read once: that is the point of the class.
     *
     * @covers ::getRules
     */
    public function test_each_gitignore_is_read_once(): void
    {
        $this->createFiles([
            '.gitignore' => "*.log\n",
            'debug.log',
            'src/File.php',
        ]);

        $sut = new GitIgnoreRules($this->repositoryPath);

        self::assertTrue($sut->isPathIgnored('/debug.log'));
        self::assertFalse($sut->isPathIgnored('/src/File.php'));

        file_put_contents($this->repositoryPath . '/.gitignore', "*.php\n");

        self::assertTrue($sut->isPathIgnored('/debug.log'));
        self::assertFalse($sut->isPathIgnored('/src/File.php'));

        // A new instance reads the changed file.
        self::assertTrue((new GitIgnoreRules($this->repositoryPath))->isPathIgnored('/src/File.php'));
    }

    /**
     * @covers ::__construct
     */
    public function test_repository_must_exist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        new GitIgnoreRules($this->repositoryPath . '/missing');
    }

    /**
     * @covers ::__construct
     */
    public function test_repository_must_be_a_directory(): void
    {
        $this->createFiles(['file.txt']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to set path');

        new GitIgnoreRules($this->repositoryPath . '/file.txt');
    }

    /**
     * As with the library, asking about a path which is not there is an error, not "not ignored".
     *
     * @covers ::isPathIgnored
     */
    public function test_path_must_exist(): void
    {
        $this->createFiles(['.gitignore' => "*.log\n"]);

        $sut = new GitIgnoreRules($this->repositoryPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $sut->isPathIgnored('/missing.log');
    }
}
