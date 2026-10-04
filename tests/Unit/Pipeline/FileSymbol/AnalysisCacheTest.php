<?php
/**
 * Writes to a directory in the system's temporary directory, which is removed after each test.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Unit\Pipeline\FileSymbol;

use BrianHenryIE\Strauss\Files\FileCodeMap;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\AnalysisCache;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\PhpFileAnalyzer;
use BrianHenryIE\Strauss\TestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\FileSymbol\AnalysisCache
 */
class AnalysisCacheTest extends TestCase
{
    protected const CONTENTS = "<?php\nnamespace Acme\\Lib;\n\nclass Widget extends \\Acme\\Base {}\n";

    protected string $cacheDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDirectory = sys_get_temp_dir() . '/strauss-analysis-cache-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->cacheDirectory);

        parent::tearDown();
    }

    protected function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        @chmod($directory, 0777);
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        /** @var \SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }

    protected function getCodeMap(string $contents = self::CONTENTS): FileCodeMap
    {
        $codeMap = (new PhpFileAnalyzer())->analyze($contents);
        self::assertInstanceOf(FileCodeMap::class, $codeMap);
        return $codeMap;
    }

    /**
     * @return string[] The path of every file in the cache directory.
     */
    protected function getCacheFiles(): array
    {
        if (!is_dir($this->cacheDirectory)) {
            return [];
        }
        $files = [];
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->cacheDirectory, FilesystemIterator::SKIP_DOTS)
        );
        /** @var \SplFileInfo $entry */
        foreach ($entries as $entry) {
            $files[] = $entry->getPathname();
        }
        sort($files);
        return $files;
    }

    /**
     * @covers ::__construct
     * @covers ::get
     * @covers ::set
     * @covers ::setByKey
     * @covers ::read
     * @covers ::encode
     */
    public function test_a_map_is_returned_for_the_same_contents(): void
    {
        $codeMap = $this->getCodeMap();

        $sut = new AnalysisCache($this->cacheDirectory);

        self::assertNull($sut->get(self::CONTENTS));

        $sut->set(self::CONTENTS, $codeMap);

        $result = $sut->get(self::CONTENTS);

        self::assertNotNull($result);
        self::assertEquals($codeMap, $result[0]);
        self::assertNotSame($codeMap, $result[0]);
        self::assertNull($result[1]);
    }

    /**
     * The entry is on disk, so a later run reads it.
     *
     * @covers ::get
     * @covers ::set
     */
    public function test_entries_are_read_by_another_instance(): void
    {
        $codeMap = $this->getCodeMap();

        (new AnalysisCache($this->cacheDirectory))->set(self::CONTENTS, $codeMap);

        $result = (new AnalysisCache($this->cacheDirectory))->get(self::CONTENTS);

        self::assertNotNull($result);
        self::assertEquals($codeMap, $result[0]);
        self::assertCount(1, $this->getCacheFiles(), 'No temporary file is left behind.');
    }

    /**
     * @covers ::get
     * @covers ::getKey
     */
    public function test_different_contents_are_a_miss(): void
    {
        $sut = new AnalysisCache($this->cacheDirectory);
        $sut->set(self::CONTENTS, $this->getCodeMap());

        self::assertNull($sut->get(self::CONTENTS . "\n"));
        self::assertNotSame($sut->getKey(self::CONTENTS), $sut->getKey(self::CONTENTS . "\n"));
        self::assertSame($sut->getKey(self::CONTENTS), $sut->getKey(self::CONTENTS));
    }

    /**
     * A file which could not be parsed is remembered with its error, so it is neither parsed again nor forgotten.
     *
     * @covers ::get
     * @covers ::set
     */
    public function test_a_parse_failure_is_cached_with_its_message(): void
    {
        $contents = "<?php\nnamespace %g_namespace%\\AdminMenus;\n";

        $sut = new AnalysisCache($this->cacheDirectory);
        $sut->set($contents, null, 'Syntax error, unexpected T_STRING on line 2');

        self::assertSame([null, 'Syntax error, unexpected T_STRING on line 2'], $sut->get($contents));
    }

    /**
     * The key lets a result be stored when only the hash of the contents was kept.
     *
     * @covers ::setByKey
     * @covers ::getKey
     */
    public function test_set_by_key(): void
    {
        $codeMap = $this->getCodeMap();

        $sut = new AnalysisCache($this->cacheDirectory);
        $sut->setByKey($sut->getKey(self::CONTENTS), $codeMap);

        $result = $sut->get(self::CONTENTS);

        self::assertNotNull($result);
        self::assertEquals($codeMap, $result[0]);
    }

    /**
     * @covers ::setByKey
     * @covers ::isKey
     */
    public function test_set_by_key_ignores_anything_which_is_not_a_key(): void
    {
        $sut = new AnalysisCache($this->cacheDirectory);

        $sut->setByKey('../../escape', $this->getCodeMap());
        $sut->setByKey('', $this->getCodeMap());

        self::assertSame([], $this->getCacheFiles());
    }

    /**
     * @covers ::get
     * @covers ::getHits
     * @covers ::getMisses
     */
    public function test_counts_hits_and_misses(): void
    {
        $sut = new AnalysisCache($this->cacheDirectory);

        $sut->get(self::CONTENTS);
        $sut->set(self::CONTENTS, $this->getCodeMap());
        $sut->get(self::CONTENTS);
        $sut->get(self::CONTENTS);
        $sut->get('<?php // Something else.');

        self::assertSame(2, $sut->getHits());
        self::assertSame(2, $sut->getMisses());
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function damagedEntryProvider(): array
    {
        return [
            'empty' => [''],
            'not an entry' => ['not an entry'],
            'truncated' => ['truncate'],
            'another value' => ['serialize:string'],
            'another class' => ['serialize:object'],
            'wrong shape' => ['serialize:shape'],
        ];
    }

    /**
     * Whatever is in an entry's file, it is either a result or a miss: never an error, and never another class.
     *
     * @dataProvider damagedEntryProvider
     *
     * @covers ::get
     * @covers ::read
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('damagedEntryProvider')]
    public function test_a_damaged_entry_is_a_miss(string $damage): void
    {
        $sut = new AnalysisCache($this->cacheDirectory);
        $sut->set(self::CONTENTS, $this->getCodeMap());

        $entryPath = $this->getCacheFiles()[0];

        $compress = fn(string $serialized): string => function_exists('gzdeflate')
            ? (string) gzdeflate($serialized)
            : $serialized;

        switch ($damage) {
            case 'truncate':
                $replacement = substr((string) file_get_contents($entryPath), 0, 20);
                break;
            case 'serialize:string':
                $replacement = $compress(serialize('a string'));
                break;
            case 'serialize:object':
                $replacement = $compress(serialize([new \ArrayObject(['not a map']), null]));
                break;
            case 'serialize:shape':
                $replacement = $compress(serialize([$this->getCodeMap(), null, 'extra']));
                break;
            default:
                $replacement = $damage;
        }
        file_put_contents($entryPath, $replacement);

        self::assertNull($sut->get(self::CONTENTS));
        self::assertSame(1, $sut->getMisses());
    }

    /**
     * An entry stored under these contents' key which describes other contents is not used.
     *
     * @covers ::get
     */
    public function test_an_entry_for_other_contents_is_a_miss(): void
    {
        $sut = new AnalysisCache($this->cacheDirectory);

        $sut->setByKey($sut->getKey(self::CONTENTS), $this->getCodeMap("<?php\nclass SomethingElse {}\n"));

        self::assertNull($sut->get(self::CONTENTS));
    }

    /**
     * @covers ::set
     * @covers ::setByKey
     */
    public function test_read_only_cache_reads_but_does_not_write(): void
    {
        $codeMap = $this->getCodeMap();
        (new AnalysisCache($this->cacheDirectory))->set(self::CONTENTS, $codeMap);
        $filesBefore = $this->getCacheFiles();

        $sut = new AnalysisCache($this->cacheDirectory, true);
        $otherContents = "<?php\nclass SomethingElse {}\n";
        $sut->set($otherContents, $this->getCodeMap($otherContents));

        self::assertNotNull($sut->get(self::CONTENTS));
        self::assertNull($sut->get($otherContents));
        self::assertSame($filesBefore, $this->getCacheFiles());
    }

    /**
     * @covers ::setByKey
     */
    public function test_read_only_cache_does_not_create_its_directory(): void
    {
        $sut = new AnalysisCache($this->cacheDirectory, true);
        $sut->set(self::CONTENTS, $this->getCodeMap());

        self::assertDirectoryDoesNotExist($this->cacheDirectory);
    }

    /**
     * A cache which cannot be written to is carried on without, and says so once.
     *
     * @covers ::setByKey
     * @covers ::prepare
     * @covers ::stopWriting
     */
    public function test_unwritable_directory_is_not_an_error(): void
    {
        // A file where the cache's directory should be.
        file_put_contents($this->cacheDirectory, 'not a directory');

        try {
            $sut = new AnalysisCache($this->cacheDirectory, false, $this->getLogger());
            $sut->set(self::CONTENTS, $this->getCodeMap());
            $sut->set(self::CONTENTS . "\n", $this->getCodeMap(self::CONTENTS . "\n"));

            self::assertNull($sut->get(self::CONTENTS));
        } finally {
            unlink($this->cacheDirectory);
        }

        $debugMessages = array_column($this->getTestLogger()->recordsByLevel['debug'] ?? [], 'message');
        self::assertCount(1, $debugMessages);
        self::assertStringContainsString('Analysis cache is not writable', $debugMessages[0]);
    }

    /**
     * Entries are stored under the fingerprint of the code which wrote them.
     *
     * @covers ::getFingerprint
     * @covers ::getParserVersion
     * @covers ::getPath
     */
    public function test_entries_are_stored_under_the_fingerprint(): void
    {
        $sut = new AnalysisCache($this->cacheDirectory);
        $sut->set(self::CONTENTS, $this->getCodeMap());

        $fingerprint = $sut->getFingerprint();
        $key = $sut->getKey(self::CONTENTS);

        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $fingerprint);
        self::assertSame($fingerprint, (new AnalysisCache($this->cacheDirectory))->getFingerprint());
        self::assertSame(
            [$this->cacheDirectory . '/' . $fingerprint . '/' . substr($key, 0, 2) . '/' . $key],
            $this->getCacheFiles()
        );
    }

    /**
     * Entries written by other code are never read.
     *
     * @covers ::get
     * @covers ::getPath
     */
    public function test_another_fingerprint_is_a_miss(): void
    {
        (new AnalysisCache($this->cacheDirectory))->set(self::CONTENTS, $this->getCodeMap());

        $sut = new class ($this->cacheDirectory) extends AnalysisCache {
            public function getFingerprint(): string
            {
                return str_repeat('a', 40);
            }
        };

        self::assertNull($sut->get(self::CONTENTS));
    }

    /**
     * The first write removes the directories of fingerprints which have not been used for a month, and nothing
     * else.
     *
     * @covers ::prepare
     * @covers ::removeDirectory
     */
    public function test_unused_fingerprints_are_removed(): void
    {
        $unused = $this->cacheDirectory . '/' . str_repeat('a', 40);
        $recent = $this->cacheDirectory . '/' . str_repeat('b', 40);
        $notAFingerprint = $this->cacheDirectory . '/keep-me';

        foreach ([$unused, $recent, $notAFingerprint] as $directory) {
            mkdir($directory . '/ab', 0777, true);
            file_put_contents($directory . '/ab/entry', 'entry');
        }
        touch($unused, time() - 31 * 24 * 60 * 60);
        touch($recent, time() - 29 * 24 * 60 * 60);
        touch($notAFingerprint, time() - 365 * 24 * 60 * 60);

        $sut = new AnalysisCache($this->cacheDirectory, false, $this->getLogger());
        $sut->set(self::CONTENTS, $this->getCodeMap());

        self::assertDirectoryDoesNotExist($unused);
        self::assertFileExists($recent . '/ab/entry');
        self::assertFileExists($notAFingerprint . '/ab/entry');
        self::assertNotNull($sut->get(self::CONTENTS));
    }

    /**
     * @covers ::prepare
     */
    public function test_read_only_cache_removes_nothing(): void
    {
        $unused = $this->cacheDirectory . '/' . str_repeat('a', 40);
        mkdir($unused, 0777, true);
        touch($unused, time() - 31 * 24 * 60 * 60);

        $sut = new AnalysisCache($this->cacheDirectory, true);
        $sut->set(self::CONTENTS, $this->getCodeMap());
        $sut->get(self::CONTENTS);

        self::assertDirectoryExists($unused);
    }

    /**
     * Using a cache marks its fingerprint as used, so another version leaves it alone.
     *
     * @covers ::prepare
     */
    public function test_writing_marks_the_fingerprint_as_used(): void
    {
        $sut = new AnalysisCache($this->cacheDirectory);
        $fingerprintDirectory = $this->cacheDirectory . '/' . $sut->getFingerprint();
        mkdir($fingerprintDirectory, 0777, true);
        touch($fingerprintDirectory, time() - 40 * 24 * 60 * 60);
        clearstatcache();

        $sut->set(self::CONTENTS, $this->getCodeMap());

        clearstatcache();
        self::assertGreaterThan(time() - 60, filemtime($fingerprintDirectory));
    }

    /**
     * With nothing in the cache when the run starts, lookups are answered without touching the disk; once
     * something has been written, they are read.
     *
     * @covers ::hasEntries
     * @covers ::get
     * @covers ::setByKey
     */
    public function test_has_entries(): void
    {
        $sut = new AnalysisCache($this->cacheDirectory);

        self::assertFalse($sut->hasEntries());
        self::assertNull($sut->get(self::CONTENTS));
        self::assertFalse($sut->hasEntries(), 'Looking something up creates the directory but no entries.');

        $sut->set(self::CONTENTS, $this->getCodeMap());

        self::assertTrue($sut->hasEntries());
        self::assertTrue((new AnalysisCache($this->cacheDirectory))->hasEntries());
        self::assertTrue((new AnalysisCache($this->cacheDirectory, true))->hasEntries());
    }

    /**
     * @covers ::getDirectory
     * @covers ::isReadOnly
     */
    public function test_getters(): void
    {
        $sut = new AnalysisCache($this->cacheDirectory . '/', true);

        self::assertSame($this->cacheDirectory, $sut->getDirectory());
        self::assertTrue($sut->isReadOnly());
        self::assertFalse((new AnalysisCache($this->cacheDirectory))->isReadOnly());
    }
}
