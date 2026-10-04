<?php
/**
 * With an analysis cache set, a file whose contents were analysed before is not parsed again, in this process or in
 * a worker.
 *
 * Uses a real {@see AnalysisCache} in the system's temporary directory, which is removed after each test.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Unit\Pipeline;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileCodeMap;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\AnalysisCache;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\PhpFileAnalyzer;
use BrianHenryIE\Strauss\Pipeline\FileSymbolScanner;
use BrianHenryIE\Strauss\Pipeline\Parallel\ParallelConfig;
use BrianHenryIE\Strauss\Pipeline\Parallel\ParallelisationException;
use BrianHenryIE\Strauss\Pipeline\Parallel\WorkerPool;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use FilesystemIterator;
use Mockery;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\FileSymbolScanner
 */
class FileSymbolScannerCacheTest extends TestCase
{
    protected const CONTENTS = [
        '/project/vendor/acme/one.php' => "<?php\nnamespace Acme\\One;\nclass First {}\n",
        '/project/vendor/acme/two.php' => "<?php\nnamespace Acme\\Two;\nclass Second {}\n",
        '/project/vendor/acme/three.php' => "<?php\nnamespace Acme\\Three;\nclass Third {}\n",
    ];

    protected const UNPARSABLE = "<?php\nnamespace %g_namespace%\\AdminMenus;\n";

    protected string $cacheDirectory;

    /**
     * The source path of every file the scanner parsed in this process, in order.
     *
     * @var string[]
     */
    public array $parsed = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDirectory = sys_get_temp_dir() . '/strauss-scanner-cache-test-' . bin2hex(random_bytes(6));
        $this->parsed = [];
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDirectory)) {
            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->cacheDirectory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            /** @var \SplFileInfo $entry */
            foreach ($entries as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->cacheDirectory);
        }

        parent::tearDown();
    }

    /**
     * @param array<string, string> $contents Keyed by path.
     *
     * @return File[]
     */
    protected function getFiles(array $contents = self::CONTENTS): array
    {
        return array_map(
            fn(string $path): File => new File($path, basename($path), $path),
            array_keys($contents)
        );
    }

    /**
     * @param File[] $files
     */
    protected function getDiscoveredFiles(array $files): DiscoveredFiles
    {
        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn($files);
        return $discoveredFiles;
    }

    /**
     * A scanner which records the files it parses in this process in {@see self::$parsed}.
     *
     * @param array<string, string> $contents Keyed by path.
     */
    protected function getSut(
        DiscoveredSymbols $discoveredSymbols,
        ?AnalysisCache $analysisCache,
        array $contents = self::CONTENTS
    ): FileSymbolScanner {
        $filesystem = Mockery::mock(FileSystem::class);
        $filesystem->shouldReceive('getRelativePath')->andReturnArg(1);
        $filesystem->shouldReceive('makeAbsolute')->andReturnUsing(fn(string $path): string => '/abs' . $path);
        $filesystem->shouldReceive('read')->andReturnUsing(fn(string $path): string => $contents[$path]);

        $config = $this->createMock(StraussConfig::class);
        $config->method('getPackagesToPrefix')->willReturn([]);

        $sut = new class ($config, $discoveredSymbols, $filesystem, $this->getLogger(), $this) extends FileSymbolScanner {
            protected FileSymbolScannerCacheTest $test;

            public function __construct($config, $discoveredSymbols, $filesystem, $logger, FileSymbolScannerCacheTest $test)
            {
                parent::__construct($config, $discoveredSymbols, $filesystem, $logger);
                $this->test = $test;
            }

            protected function getAnalyzer(): PhpFileAnalyzer
            {
                return $this->analyzer ??= new class ($this->logger, $this->test) extends PhpFileAnalyzer {
                    protected FileSymbolScannerCacheTest $test;

                    public function __construct($logger, FileSymbolScannerCacheTest $test)
                    {
                        parent::__construct($logger);
                        $this->test = $test;
                    }

                    public function analyze(string $contents, ?string $filePathForLogging = null): ?FileCodeMap
                    {
                        $this->test->parsed[] = (string) $filePathForLogging;
                        return parent::analyze($contents, $filePathForLogging);
                    }
                };
            }
        };
        $sut->setAnalysisCache($analysisCache);

        return $sut;
    }

    protected function getCache(bool $readOnly = false): AnalysisCache
    {
        return new AnalysisCache($this->cacheDirectory, $readOnly);
    }

    /**
     * @return WorkerPool&\Mockery\MockInterface
     */
    protected function getWorkerPoolMock(int $filesPerChunk = 1)
    {
        $workerPool = Mockery::mock(WorkerPool::class);
        $workerPool->shouldReceive('getConfig')->andReturn(new ParallelConfig(2, $filesPerChunk));
        return $workerPool;
    }

    /**
     * The first run parses every file; a second run, with new objects, parses none and discovers the same symbols.
     *
     * @covers ::setAnalysisCache
     * @covers ::find
     * @covers ::findInFiles
     */
    public function test_second_run_parses_nothing(): void
    {
        $firstSymbols = new DiscoveredSymbols();
        $firstCache = $this->getCache();
        $this->getSut($firstSymbols, $firstCache)->findInFiles($this->getDiscoveredFiles($this->getFiles()));

        self::assertSame(array_keys(self::CONTENTS), $this->parsed);
        self::assertSame(0, $firstCache->getHits());
        self::assertSame(3, $firstCache->getMisses());

        $this->parsed = [];
        $secondSymbols = new DiscoveredSymbols();
        $secondCache = $this->getCache();
        $files = $this->getFiles();
        $this->getSut($secondSymbols, $secondCache)->findInFiles($this->getDiscoveredFiles($files));

        self::assertSame([], $this->parsed);
        self::assertSame(3, $secondCache->getHits());
        self::assertSame(0, $secondCache->getMisses());

        foreach ($files as $file) {
            self::assertNotNull($file->getCodeMap(), $file->getSourcePath());
            self::assertTrue($file->getCodeMap()->matchesContent(self::CONTENTS[$file->getSourcePath()]));
        }
        self::assertSame(
            array_keys($firstSymbols->toArray()),
            array_keys($secondSymbols->toArray())
        );
        self::assertNotNull($secondSymbols->getClass('Acme\Three\Third'));
    }

    /**
     * Only the file whose contents changed is parsed.
     *
     * @covers ::find
     */
    public function test_a_changed_file_is_parsed_again(): void
    {
        $this->getSut(new DiscoveredSymbols(), $this->getCache())
            ->findInFiles($this->getDiscoveredFiles($this->getFiles()));

        $this->parsed = [];
        $changed = self::CONTENTS;
        $changed['/project/vendor/acme/two.php'] = "<?php\nnamespace Acme\\Two;\nclass Renamed {}\n";

        $symbols = new DiscoveredSymbols();
        $this->getSut($symbols, $this->getCache(), $changed)->findInFiles($this->getDiscoveredFiles($this->getFiles()));

        self::assertSame(['/project/vendor/acme/two.php'], $this->parsed);
        self::assertNotNull($symbols->getClass('Acme\Two\Renamed'));
        self::assertNull($symbols->getClass('Acme\Two\Second'));
    }

    /**
     * An unparsable file is warned about on every run, but only parsed on the first.
     *
     * @covers ::find
     * @covers ::logParseFailure
     */
    public function test_cached_parse_failure_is_logged_without_parsing(): void
    {
        $contents = ['/project/vendor/acme/template.php' => self::UNPARSABLE];

        $this->expectWarningLogs();

        $this->getSut(new DiscoveredSymbols(), $this->getCache(), $contents)
            ->findInFiles($this->getDiscoveredFiles($this->getFiles($contents)));

        self::assertCount(1, $this->parsed);
        $this->parsed = [];

        $files = $this->getFiles($contents);
        $this->getSut(new DiscoveredSymbols(), $this->getCache(), $contents)
            ->findInFiles($this->getDiscoveredFiles($files));

        self::assertSame([], $this->parsed);
        self::assertNull($files[0]->getCodeMap());

        $warnings = array_column($this->getTestLogger()->recordsByLevel['warning'], 'message');
        self::assertCount(2, $warnings);
        self::assertStringContainsString('/project/vendor/acme/template.php', $warnings[1]);
        self::assertStringContainsString('Syntax error', $warnings[1]);
    }

    /**
     * @covers ::find
     */
    public function test_read_only_cache_is_not_written_to(): void
    {
        $this->getSut(new DiscoveredSymbols(), $this->getCache(true))
            ->findInFiles($this->getDiscoveredFiles($this->getFiles()));

        self::assertCount(3, $this->parsed);
        self::assertDirectoryDoesNotExist($this->cacheDirectory);
    }

    /**
     * Files in the cache are not sent to the workers, which are told where the cache is so they can add to it.
     *
     * @covers ::analyzeInParallel
     * @covers ::findInFiles
     */
    public function test_only_uncached_files_are_sent_to_workers(): void
    {
        // Only `one.php` is cached.
        $first = array_slice(self::CONTENTS, 0, 1, true);
        $this->getSut(new DiscoveredSymbols(), $this->getCache(), $first)
            ->findInFiles($this->getDiscoveredFiles($this->getFiles($first)));
        $this->parsed = [];

        $workerPool = $this->getWorkerPoolMock();
        $workerPool->shouldReceive('analyze')
            ->once()
            ->andReturnUsing(function (array $absolutePaths, callable $onResult, ?string $cacheDirectory): void {
                self::assertSame(
                    ['/abs/project/vendor/acme/two.php', '/abs/project/vendor/acme/three.php'],
                    $absolutePaths
                );
                self::assertSame($this->cacheDirectory, $cacheDirectory);
                foreach ($absolutePaths as $absolutePath) {
                    $sourcePath = substr($absolutePath, strlen('/abs'));
                    $onResult($absolutePath, (new PhpFileAnalyzer())->analyze(self::CONTENTS[$sourcePath]), null);
                }
            });

        $symbols = new DiscoveredSymbols();
        $cache = $this->getCache();
        $sut = $this->getSut($symbols, $cache);
        $sut->setWorkerPool($workerPool);
        $sut->findInFiles($this->getDiscoveredFiles($this->getFiles()));

        self::assertSame([], $this->parsed, 'Nothing is parsed in this process.');
        self::assertSame(1, $cache->getHits());
        self::assertSame(2, $cache->getMisses());
        self::assertSame(
            ['Acme\One\First', 'Acme\Two\Second', 'Acme\Three\Third'],
            array_keys($symbols->getAllClasses()->toArray())
        );
    }

    /**
     * Every file is cached: no worker is started.
     *
     * @covers ::analyzeInParallel
     */
    public function test_workers_are_not_used_when_every_file_is_cached(): void
    {
        $this->getSut(new DiscoveredSymbols(), $this->getCache())
            ->findInFiles($this->getDiscoveredFiles($this->getFiles()));
        $this->parsed = [];

        $workerPool = $this->getWorkerPoolMock();
        $workerPool->shouldNotReceive('analyze');

        $cache = $this->getCache();
        $sut = $this->getSut(new DiscoveredSymbols(), $cache);
        $sut->setWorkerPool($workerPool);
        $sut->findInFiles($this->getDiscoveredFiles($this->getFiles()));

        self::assertSame([], $this->parsed);
        self::assertSame(3, $cache->getHits());
        self::assertSame(0, $cache->getMisses());
    }

    /**
     * Workers are not told about a cache which must not be written to, or when there is none.
     *
     * @covers ::analyzeInParallel
     */
    public function test_workers_are_not_given_a_read_only_cache(): void
    {
        foreach ([$this->getCache(true), null] as $cache) {
            $workerPool = $this->getWorkerPoolMock();
            $workerPool->shouldReceive('analyze')
                ->once()
                ->andReturnUsing(function (array $absolutePaths, callable $onResult, ?string $cacheDirectory): void {
                    self::assertNull($cacheDirectory);
                });

            $sut = $this->getSut(new DiscoveredSymbols(), $cache);
            $sut->setWorkerPool($workerPool);
            $sut->findInFiles($this->getDiscoveredFiles($this->getFiles()));
        }

        self::assertDirectoryDoesNotExist($this->cacheDirectory);
    }

    /**
     * Too few uncached files for the workers: they are parsed here, and each is only looked up in the cache once.
     *
     * @covers ::analyzeInParallel
     * @covers ::find
     */
    public function test_few_uncached_files_are_parsed_in_this_process(): void
    {
        $workerPool = $this->getWorkerPoolMock(20);
        $workerPool->shouldNotReceive('analyze');

        $cache = $this->getCache();
        $sut = $this->getSut(new DiscoveredSymbols(), $cache);
        $sut->setWorkerPool($workerPool);
        $sut->findInFiles($this->getDiscoveredFiles($this->getFiles()));

        self::assertSame(array_keys(self::CONTENTS), $this->parsed);
        self::assertSame(0, $cache->getHits());
        self::assertSame(3, $cache->getMisses());

        // And they were added to the cache.
        self::assertNotNull($this->getCache()->get(self::CONTENTS['/project/vendor/acme/two.php']));
    }

    /**
     * When the workers fail, the cached results are still used and only the rest is parsed in this process.
     *
     * @covers ::analyzeInParallel
     * @covers ::findInFiles
     */
    public function test_cached_results_survive_a_worker_failure(): void
    {
        $first = array_slice(self::CONTENTS, 0, 1, true);
        $this->getSut(new DiscoveredSymbols(), $this->getCache(), $first)
            ->findInFiles($this->getDiscoveredFiles($this->getFiles($first)));
        $this->parsed = [];

        $workerPool = $this->getWorkerPoolMock();
        $workerPool->shouldReceive('analyze')
            ->once()
            ->andReturnUsing(function (array $absolutePaths, callable $onResult): void {
                $onResult($absolutePaths[0], null, 'Should be discarded');
                throw new ParallelisationException('Worker process exited with code 255');
            });

        $this->expectWarningLogs();

        $symbols = new DiscoveredSymbols();
        $sut = $this->getSut($symbols, $this->getCache());
        $sut->setWorkerPool($workerPool);
        $sut->findInFiles($this->getDiscoveredFiles($this->getFiles()));

        self::assertSame(['/project/vendor/acme/two.php', '/project/vendor/acme/three.php'], $this->parsed);
        self::assertNotNull($symbols->getClass('Acme\One\First'));
        self::assertNotNull($symbols->getClass('Acme\Two\Second'));
        self::assertNotNull($symbols->getClass('Acme\Three\Third'));
    }

    /**
     * @covers ::setAnalysisCache
     * @covers ::find
     */
    public function test_without_a_cache_every_file_is_parsed(): void
    {
        $this->getSut(new DiscoveredSymbols(), null)->findInFiles($this->getDiscoveredFiles($this->getFiles()));
        $this->parsed = [];
        $this->getSut(new DiscoveredSymbols(), null)->findInFiles($this->getDiscoveredFiles($this->getFiles()));

        self::assertSame(array_keys(self::CONTENTS), $this->parsed);
        self::assertDirectoryDoesNotExist($this->cacheDirectory);
    }
}
