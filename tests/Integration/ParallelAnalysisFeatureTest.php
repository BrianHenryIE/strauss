<?php
/**
 * Analysing files in worker processes (`--parallel`) must produce exactly the same output as in one process.
 *
 * @see \BrianHenryIE\Strauss\Pipeline\Parallel\WorkerPool
 * @see \BrianHenryIE\Strauss\Pipeline\FileSymbolScanner::analyzeInParallel()
 */

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @coversNothing
 */
class ParallelAnalysisFeatureTest extends IntegrationTestCase
{
    /**
     * Every file under the directory and its contents, keyed by relative path.
     *
     * @return array<string, string>
     */
    protected function snapshotDirectory(string $directory): array
    {
        $snapshot = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                // Composer's autoloader class suffix is random per `dump-autoload`, so not part of the comparison.
                $snapshot[substr($file->getPathname(), strlen($directory))] = (string) preg_replace(
                    '/(ComposerAutoloaderInit|ComposerStaticInit)[0-9a-f]{32}/',
                    '$1HASH',
                    (string) file_get_contents($file->getPathname())
                );
            }
        }
        ksort($snapshot);
        return $snapshot;
    }

    /**
     * Run Strauss sequentially and in parallel on the same project, comparing the prefixed output.
     */
    protected function assertParallelMatchesSequential(string $projectDir): void
    {
        $logger = $this->getTestLogger();

        $exitCode = $this->runStrauss($sequentialOutput, '--parallel=1 --debug');
        $this->assertEquals(0, $exitCode, $sequentialOutput);
        $this->assertFalse($logger->hasDebugThatMatches('/worker processes/'), 'Sequential run should not use workers.');
        $sequential = $this->snapshotDirectory($projectDir . '/vendor-prefixed');
        $this->assertNotEmpty($sequential);

        exec('rm -rf ' . escapeshellarg($projectDir . '/vendor-prefixed'));
        @unlink($projectDir . '/vendor/composer/autoload_aliases.php');
        $logger->reset();

        $exitCode = $this->runStrauss($parallelOutput, '--parallel=4 --debug');
        $this->assertEquals(0, $exitCode, $parallelOutput);
        if(!$this->isTestingWithPhar()) {
            $this->assertTrue( $logger->hasDebugThatMatches( '/Analysing \d+ files with \d+ worker processes/' ), 'Parallel run should use workers.' );
            $this->assertFalse( $logger->hasWarningThatContains( 'Could not analyse files in parallel' ) );
        }
        $parallel = $this->snapshotDirectory($projectDir . '/vendor-prefixed');

        $this->assertSame(array_keys($sequential), array_keys($parallel), 'Different files were written.');
        foreach ($sequential as $path => $contents) {
            $this->assertSame($contents, $parallel[$path], "Contents of $path differ between sequential and parallel runs.");
        }
    }

    public function test_parallel_output_matches_sequential(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "strauss/parallel-parity",
  "require": {
    "nikic/php-parser": "5.3.1"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Strauss\\Parity\\",
      "classmap_prefix": "Strauss_Parity_"
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);
        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);
        exec('composer install');

        $this->assertParallelMatchesSequential($this->testsWorkingDir);

        // Symbol discovery still happens in this process, in file order, whichever worker parsed the file.
        if(!$this->isTestingWithPhar()) {
            $this->assertTrue( $this->getTestLogger()->hasInfoThatContains( 'Found new class:::PhpParser\Node\Name' ) );
        }
    }

    /**
     * Workers are given the real path of the file to parse, which for a symlinked package is outside the project.
     */
    public function test_parallel_with_symlinked_path_repository(): void
    {
        $this->markTestSkippedOnWindows('symlinks');

        $projectDir = $this->testsWorkingDir . '/project';
        $packageDir = $this->testsWorkingDir . '/symlinked-package';
        mkdir($projectDir);
        mkdir($packageDir . '/src', 0777, true);

        $this->getFileSystem()->write($packageDir . '/composer.json', <<<'EOD'
{
  "name": "strauss-test/symlinked-package",
  "autoload": {
    "psr-4": {
      "Symlinked\\Package\\": "src/"
    }
  }
}
EOD);
        // More files than one chunk, so the workers are used.
        for ($i = 0; $i < 30; $i++) {
            $this->getFileSystem()->write(
                $packageDir . "/src/Thing$i.php",
                "<?php\nnamespace Symlinked\\Package;\n\nclass Thing$i\n{\n    public function other(): ?Thing" . (($i + 1) % 30) . "\n    {\n        return null;\n    }\n}\n"
            );
        }

        $this->getFileSystem()->write($projectDir . '/composer.json', <<<'EOD'
{
  "name": "strauss-test/project",
  "repositories": [
    {
      "type": "path",
      "url": "../symlinked-package",
      "options": {
        "symlink": true
      }
    }
  ],
  "require": {
    "strauss-test/symlinked-package": "@dev"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Strauss\\Parity\\"
    }
  }
}
EOD);

        chdir($projectDir);
        exec('composer install');
        $this->assertTrue(is_link($projectDir . '/vendor/strauss-test/symlinked-package'), 'Package should be symlinked.');

        $this->assertParallelMatchesSequential($projectDir);

        $prefixed = (string) file_get_contents($projectDir . '/vendor-prefixed/strauss-test/symlinked-package/src/Thing3.php');
        $this->assertStringContainsString('namespace Strauss\Parity\Symlinked\Package;', $prefixed);
        $this->assertStringContainsString('?Thing4', $prefixed);
    }
}
