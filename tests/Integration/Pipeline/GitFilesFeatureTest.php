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
        $config->method('getExcludeGitFiles')->willReturn($excludeGitFiles);
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
}
