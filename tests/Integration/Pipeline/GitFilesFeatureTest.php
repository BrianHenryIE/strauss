<?php

namespace BrianHenryIE\Strauss\Tests\Integration\Pipeline;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\IntegrationTestCase;
use BrianHenryIE\Strauss\Pipeline\FileEnumerator;

/**
 * Verifies that FileEnumerator skips `.git`, `.gitignore`-matched and `.gitattributes export-ignore`
 * files, and that the behaviour can be disabled via the `exclude_git_files` config flag.
 *
 * @coversNothing
 */
class GitFilesFeatureTest extends IntegrationTestCase
{
    /**
     * Create a package directory on disk with files that should be kept and files that Git would
     * exclude from the distributed archive.
     *
     * @return string The absolute path to the package directory.
     */
    private function createTestPackage(): string
    {
        $packageDir = $this->testsWorkingDir . '/package';
        $filesystem = $this->getFileSystem();

        // Files which should be copied.
        $filesystem->write($packageDir . '/src/Real.php', '<?php // keep');
        $filesystem->write($packageDir . '/README.md', '# Keep');

        // `.git` internals.
        $filesystem->write($packageDir . '/.git/config', '[core]');

        // `.gitignore`-matched files.
        $filesystem->write($packageDir . '/.gitignore', "build/\n*.log\n");
        $filesystem->write($packageDir . '/build/generated.php', '<?php // ignored');
        $filesystem->write($packageDir . '/debug.log', 'ignored');

        // `.gitattributes export-ignore` files.
        $filesystem->write($packageDir . '/.gitattributes', "/tests export-ignore\nphpunit.xml export-ignore\n");
        $filesystem->write($packageDir . '/tests/RealTest.php', '<?php // export-ignored');
        $filesystem->write($packageDir . '/phpunit.xml', '<phpunit/>');

        return $packageDir;
    }

    /**
     * @param DiscoveredFiles $files
     * @return string[] The discovered files' source paths.
     */
    private function getSourcePaths(DiscoveredFiles $files): array
    {
        return array_map(
            fn($file): string => $file->getSourcePath(),
            $files->getFiles()
        );
    }

    private function assertDiscoveredContains(DiscoveredFiles $files, string $relativePath): void
    {
        foreach ($this->getSourcePaths($files) as $sourcePath) {
            if (substr($sourcePath, -strlen($relativePath)) === $relativePath) {
                $this->addToAssertionCount(1);
                return;
            }
        }
        $this->fail($relativePath . ' should have been discovered. Found: ' . implode(', ', $this->getSourcePaths($files)));
    }

    private function assertDiscoveredNotContains(DiscoveredFiles $files, string $relativePath): void
    {
        foreach ($this->getSourcePaths($files) as $sourcePath) {
            if (substr($sourcePath, -strlen($relativePath)) === $relativePath) {
                $this->fail($relativePath . ' should not have been discovered. Found: ' . implode(', ', $this->getSourcePaths($files)));
            }
        }
        $this->addToAssertionCount(1);
    }

    private function createConfig(bool $excludeGitFiles): StraussConfig
    {
        $config = $this->createStub(StraussConfig::class);
        $config->method('isExcludeGitFiles')->willReturn($excludeGitFiles);
        $config->method('getAbsoluteVendorDirectory')->willReturn($this->testsWorkingDir);
        $config->method('getAbsoluteTargetDirectory')->willReturn($this->testsWorkingDir . '/vendor-prefixed');
        return $config;
    }

    public function test_git_files_are_excluded_by_default(): void
    {
        $packageDir = $this->createTestPackage();

        $fileEnumerator = new FileEnumerator(
            $this->createConfig(true),
            $this->getFileSystem(),
            $this->getLogger()
        );

        $files = $fileEnumerator->compileFileListForPaths([$packageDir]);

        // Kept.
        $this->assertDiscoveredContains($files, 'package/src/Real.php');
        $this->assertDiscoveredContains($files, 'package/README.md');

        // Excluded.
        $this->assertDiscoveredNotContains($files, '.git/config');
        $this->assertDiscoveredNotContains($files, 'build/generated.php');
        $this->assertDiscoveredNotContains($files, 'debug.log');
        $this->assertDiscoveredNotContains($files, 'tests/RealTest.php');
        $this->assertDiscoveredNotContains($files, 'phpunit.xml');
    }

    public function test_git_files_are_included_when_flag_disabled(): void
    {
        $packageDir = $this->createTestPackage();

        $fileEnumerator = new FileEnumerator(
            $this->createConfig(false),
            $this->getFileSystem(),
            $this->getLogger()
        );

        $files = $fileEnumerator->compileFileListForPaths([$packageDir]);

        // With the flag disabled, every file is discovered (current/legacy behaviour).
        $this->assertDiscoveredContains($files, 'package/src/Real.php');
        $this->assertDiscoveredContains($files, '.git/config');
        $this->assertDiscoveredContains($files, 'build/generated.php');
        $this->assertDiscoveredContains($files, 'debug.log');
        $this->assertDiscoveredContains($files, 'tests/RealTest.php');
        $this->assertDiscoveredContains($files, 'phpunit.xml');
    }

    /**
     * The `.git` directory is never part of a distributed package, so it must be excluded whenever the
     * flag is enabled – even for a package that has no `.gitignore`/`.gitattributes` to otherwise
     * trigger Git processing.
     */
    public function test_git_directory_is_excluded_without_gitignore_or_gitattributes(): void
    {
        $packageDir = $this->testsWorkingDir . '/package-no-dotfiles';
        $filesystem = $this->getFileSystem();
        $filesystem->write($packageDir . '/src/Real.php', '<?php // keep');
        $filesystem->write($packageDir . '/.git/config', '[core]');

        $fileEnumerator = new FileEnumerator(
            $this->createConfig(true),
            $this->getFileSystem(),
            $this->getLogger()
        );

        $files = $fileEnumerator->compileFileListForPaths([$packageDir]);

        $this->assertDiscoveredContains($files, 'package-no-dotfiles/src/Real.php');
        $this->assertDiscoveredNotContains($files, '.git/config');
    }

    /**
     * The performance optimisation prunes Git-excluded top-level entries (`.git`, the `.gitignore`d
     * `build/`, the `export-ignore`d `tests/`) from a shallow listing *before* recursing, so those
     * directories are never deep-listed.
     *
     * This is the whole reason `compileFileListForPaths()` uses a two-pass approach. Asserting only on
     * the discovered file list (as the tests above do) would not catch a regression to the old
     * single-pass code, because both approaches produce the same final list — the difference is only
     * in which directories get walked.
     */
    public function test_excluded_directories_are_not_deep_listed(): void
    {
        $packageDir = $this->createTestPackage();

        $spyFilesystem = $this->getListContentsSpyFileSystem();

        $fileEnumerator = new FileEnumerator(
            $this->createConfig(true),
            $spyFilesystem,
            $this->getLogger()
        );

        $fileEnumerator->compileFileListForPaths([$packageDir]);

        $deepListedPaths = array_map(
            fn(array $call): string => $call['location'],
            array_filter($spyFilesystem->listContentsCalls, fn(array $call): bool => $call['deep'])
        );

        foreach ($deepListedPaths as $path) {
            $this->assertDoesNotMatchRegularExpression('#/\.git(/|$)#', $path, '.git must not be deep-listed: ' . $path);
            $this->assertDoesNotMatchRegularExpression('#/build(/|$)#', $path, 'Git-ignored build/ must not be deep-listed: ' . $path);
            $this->assertDoesNotMatchRegularExpression('#/tests(/|$)#', $path, 'export-ignored tests/ must not be deep-listed: ' . $path);
        }

        // Sanity check: the kept `src/` directory *was* deep-listed, proving enumeration actually ran
        // (so the assertions above are not vacuously true against an empty list).
        $srcWasDeepListed = array_filter($deepListedPaths, fn(string $path): bool => (bool) preg_match('#/src$#', $path));
        $this->assertNotEmpty(
            $srcWasDeepListed,
            'The kept src/ directory should have been deep-listed. Deep-listed: ' . implode(', ', $deepListedPaths)
        );
    }

    /**
     * A {@see FileSystem} which records every `listContents()` call so a test can assert which
     * directories were walked, and whether the walk was deep (recursive) or shallow.
     */
    private function getListContentsSpyFileSystem(): ListContentsSpyFileSystem
    {
        $realFilesystem = $this->getFileSystem();

        // Reuse the real filesystem's underlying Flysystem operator and working directory so the spy
        // reads exactly the same files, differing only in that it records the listContents() calls.
        $reflection = new \ReflectionObject($realFilesystem);
        $flysystemProperty = $reflection->getProperty('flysystem');
        $flysystemProperty->setAccessible(true);
        $workingDirProperty = $reflection->getProperty('workingDir');
        $workingDirProperty->setAccessible(true);

        return new ListContentsSpyFileSystem(
            $flysystemProperty->getValue($realFilesystem),
            $workingDirProperty->getValue($realFilesystem)
        );
    }
}
