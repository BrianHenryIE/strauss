<?php
/**
 * With a worker pool set, files are parsed by the pool and the scanner only records what it returned.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Unit\Pipeline;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\PhpFileAnalyzer;
use BrianHenryIE\Strauss\Pipeline\FileSymbolScanner;
use BrianHenryIE\Strauss\Pipeline\Parallel\ParallelConfig;
use BrianHenryIE\Strauss\Pipeline\Parallel\ParallelisationException;
use BrianHenryIE\Strauss\Pipeline\Parallel\WorkerPool;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\FileSymbolScanner
 */
class FileSymbolScannerParallelTest extends TestCase
{
    protected const CONTENTS = [
        '/project/vendor/acme/one.php' => "<?php\nnamespace Acme\\One;\nclass First {}\n",
        '/project/vendor/acme/two.php' => "<?php\nnamespace Acme\\Two;\nclass Second {}\n",
        '/project/vendor/acme/three.php' => "<?php\nnamespace Acme\\Three;\nclass Third {}\n",
    ];

    /**
     * @return File[]
     */
    protected function getFiles(): array
    {
        return array_map(
            fn(string $path): File => new File($path, basename($path), $path),
            array_keys(self::CONTENTS)
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
     * A filesystem whose "absolute" path differs from the source path, as the scanner must map results back.
     *
     * @param bool $expectReads Are the files expected to be read (and parsed) in this process?
     *
     * @return FileSystem&\Mockery\MockInterface
     */
    protected function getFilesystemMock(bool $expectReads)
    {
        $filesystem = Mockery::mock(FileSystem::class);
        $filesystem->shouldReceive('getRelativePath')->andReturnArg(1);
        $filesystem->shouldReceive('makeAbsolute')->andReturnUsing(fn(string $path): string => '/abs' . $path);
        if ($expectReads) {
            $filesystem->shouldReceive('read')
                ->times(count(self::CONTENTS))
                ->andReturnUsing(fn(string $path): string => self::CONTENTS[$path]);
        } else {
            $filesystem->shouldNotReceive('read');
        }
        return $filesystem;
    }

    /**
     * @param FileSystem&\Mockery\MockInterface $filesystem
     */
    protected function getSut($filesystem, DiscoveredSymbols $discoveredSymbols): FileSymbolScanner
    {
        $config = $this->createMock(StraussConfig::class);
        $config->method('getPackagesToPrefix')->willReturn([]);

        return new FileSymbolScanner($config, $discoveredSymbols, $filesystem, $this->getLogger());
    }

    /**
     * @param int $filesPerChunk The pool is only used for more files than this.
     *
     * @return WorkerPool&\Mockery\MockInterface
     */
    protected function getWorkerPoolMock(int $filesPerChunk = 1)
    {
        $workerPool = Mockery::mock(WorkerPool::class);
        $workerPool->shouldReceive('getConfig')->andReturn(new ParallelConfig(2, $filesPerChunk));
        return $workerPool;
    }

    /**
     * The pool is given absolute paths; its results are recorded against the files without reading them here.
     *
     * @covers ::setWorkerPool
     * @covers ::analyzeInParallel
     * @covers ::findInFiles
     * @covers ::record
     */
    public function test_results_from_workers_are_recorded(): void
    {
        $files = $this->getFiles();
        $notPhp = new File('/project/vendor/acme/readme.md', 'readme.md', '/project/vendor/acme/readme.md');

        $workerPool = $this->getWorkerPoolMock();
        $workerPool->shouldReceive('analyze')
            ->once()
            ->andReturnUsing(function (array $absolutePaths, callable $onResult): void {
                self::assertSame(
                    array_map(fn(string $path): string => '/abs' . $path, array_keys(self::CONTENTS)),
                    $absolutePaths
                );
                // Results arrive in no particular order.
                foreach (array_reverse($absolutePaths) as $absolutePath) {
                    $sourcePath = substr($absolutePath, strlen('/abs'));
                    $onResult(
                        $absolutePath,
                        (new PhpFileAnalyzer())->analyze(self::CONTENTS[$sourcePath], $sourcePath),
                        null
                    );
                }
            });

        $discoveredSymbols = new DiscoveredSymbols();
        $sut = $this->getSut($this->getFilesystemMock(false), $discoveredSymbols);
        $sut->setWorkerPool($workerPool);

        $sut->findInFiles($this->getDiscoveredFiles(array_merge($files, [$notPhp])));

        foreach ($files as $file) {
            self::assertNotNull($file->getCodeMap(), $file->getSourcePath());
            self::assertTrue($file->getCodeMap()->matchesContent(self::CONTENTS[$file->getSourcePath()]));
        }
        self::assertNotNull($discoveredSymbols->getClass('Acme\One\First'));
        self::assertNotNull($discoveredSymbols->getClass('Acme\Two\Second'));
        self::assertNotNull($discoveredSymbols->getClass('Acme\Three\Third'));

        // Discovered in the files' order, not the order the results arrived in.
        self::assertSame(
            ['Acme\One\First', 'Acme\Two\Second', 'Acme\Three\Third'],
            array_keys($discoveredSymbols->getAllClasses()->toArray())
        );
    }

    /**
     * A file the worker could not parse is logged as it is when parsed in this process, and is not read again.
     *
     * @covers ::analyzeInParallel
     * @covers ::findInFiles
     * @covers ::record
     */
    public function test_parse_error_from_worker_is_logged(): void
    {
        $files = $this->getFiles();

        $workerPool = $this->getWorkerPoolMock();
        $workerPool->shouldReceive('analyze')
            ->once()
            ->andReturnUsing(function (array $absolutePaths, callable $onResult): void {
                foreach ($absolutePaths as $index => $absolutePath) {
                    $sourcePath = substr($absolutePath, strlen('/abs'));
                    if (0 === $index) {
                        $onResult($absolutePath, null, 'Syntax error, unexpected T_STRING on line 2');
                        continue;
                    }
                    $onResult(
                        $absolutePath,
                        (new PhpFileAnalyzer())->analyze(self::CONTENTS[$sourcePath], $sourcePath),
                        null
                    );
                }
            });

        $discoveredSymbols = new DiscoveredSymbols();
        $sut = $this->getSut($this->getFilesystemMock(false), $discoveredSymbols);
        $sut->setWorkerPool($workerPool);

        $this->expectWarningLogs();

        $sut->findInFiles($this->getDiscoveredFiles($files));

        self::assertNull($files[0]->getCodeMap());
        self::assertNotNull($files[1]->getCodeMap());
        self::assertNull($discoveredSymbols->getClass('Acme\One\First'));
        self::assertNotNull($discoveredSymbols->getClass('Acme\Two\Second'));

        $warnings = array_column($this->getTestLogger()->recordsByLevel['warning'], 'message');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('/project/vendor/acme/one.php', $warnings[0]);
        self::assertStringContainsString('Syntax error, unexpected T_STRING on line 2', $warnings[0]);
    }

    /**
     * When the workers fail, partial results are discarded and every file is parsed in this process.
     *
     * @covers ::analyzeInParallel
     * @covers ::findInFiles
     */
    public function test_falls_back_to_this_process_when_workers_fail(): void
    {
        $files = $this->getFiles();

        $workerPool = $this->getWorkerPoolMock();
        $workerPool->shouldReceive('analyze')
            ->once()
            ->andReturnUsing(function (array $absolutePaths, callable $onResult): void {
                // One (wrong) result arrives before the failure; it must not be used.
                $onResult($absolutePaths[0], null, 'Should be discarded');
                throw new ParallelisationException('Worker process exited with code 255');
            });

        $discoveredSymbols = new DiscoveredSymbols();
        $sut = $this->getSut($this->getFilesystemMock(true), $discoveredSymbols);
        $sut->setWorkerPool($workerPool);

        $this->expectWarningLogs();

        $sut->findInFiles($this->getDiscoveredFiles($files));

        foreach ($files as $file) {
            self::assertNotNull($file->getCodeMap(), $file->getSourcePath());
        }
        self::assertNotNull($discoveredSymbols->getClass('Acme\One\First'));

        $warnings = array_column($this->getTestLogger()->recordsByLevel['warning'], 'message');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('Could not analyse files in parallel', $warnings[0]);
        self::assertStringContainsString('Worker process exited with code 255', $warnings[0]);
    }

    /**
     * A file the pool returned nothing for is parsed in this process.
     *
     * @covers ::analyzeInParallel
     * @covers ::findInFiles
     */
    public function test_files_without_a_result_are_parsed_in_this_process(): void
    {
        $files = $this->getFiles();

        $workerPool = $this->getWorkerPoolMock();
        $workerPool->shouldReceive('analyze')->once();

        $discoveredSymbols = new DiscoveredSymbols();
        $sut = $this->getSut($this->getFilesystemMock(true), $discoveredSymbols);
        $sut->setWorkerPool($workerPool);

        $sut->findInFiles($this->getDiscoveredFiles($files));

        self::assertNotNull($discoveredSymbols->getClass('Acme\Three\Third'));
    }

    /**
     * Starting workers is not worth it for no more than one chunk of files.
     *
     * @covers ::analyzeInParallel
     * @covers ::findInFiles
     */
    public function test_pool_is_not_used_for_a_single_chunk_of_files(): void
    {
        $files = $this->getFiles();

        $workerPool = $this->getWorkerPoolMock(count($files));
        $workerPool->shouldNotReceive('analyze');

        $discoveredSymbols = new DiscoveredSymbols();
        $sut = $this->getSut($this->getFilesystemMock(true), $discoveredSymbols);
        $sut->setWorkerPool($workerPool);

        $sut->findInFiles($this->getDiscoveredFiles($files));

        self::assertNotNull($discoveredSymbols->getClass('Acme\One\First'));
    }

    /**
     * @covers ::setWorkerPool
     * @covers ::analyzeInParallel
     */
    public function test_without_a_pool_files_are_parsed_in_this_process(): void
    {
        $files = $this->getFiles();

        $discoveredSymbols = new DiscoveredSymbols();
        $sut = $this->getSut($this->getFilesystemMock(true), $discoveredSymbols);
        $sut->setWorkerPool(null);

        $sut->findInFiles($this->getDiscoveredFiles($files));

        self::assertNotNull($discoveredSymbols->getClass('Acme\Two\Second'));
    }
}
