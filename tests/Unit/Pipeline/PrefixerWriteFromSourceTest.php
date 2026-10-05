<?php
/**
 * A PHP file with planned changes is written to its target once, with the changes made, rather than copied and
 * then read back, changed and written again. Every other file is copied, and updated afterwards, as before.
 *
 * Uses the tests' working directory on the real filesystem: the symlink check needs real paths.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Unit\Pipeline;

use BrianHenryIE\Strauss\Config\CopierConfigInterface;
use BrianHenryIE\Strauss\Config\PrefixerConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\Pipeline\ChangePlanner;
use BrianHenryIE\Strauss\Pipeline\Copier;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\PhpFileAnalyzer;
use BrianHenryIE\Strauss\Pipeline\Prefixer;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\Prefixer
 */
class PrefixerWriteFromSourceTest extends TestCase
{
    protected const SOURCE_CONTENTS = "<?php\n\nnamespace Acme\\Lib;\n\nclass Widget\n{\n}\n";

    protected const PREFIXED_CONTENTS = "<?php\n\nnamespace Prefix\\Acme\\Lib;\n\nclass Widget\n{\n}\n";

    /** No reference to anything being renamed. */
    protected const UNCHANGED_CONTENTS = "<?php\n\nreturn ['a' => 1];\n";

    protected FileSystem $realFilesystem;

    protected string $sourceDir;

    protected string $targetDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->realFilesystem = $this->getFileSystem();
        $this->sourceDir = $this->testsWorkingDir . '/vendor/acme/lib';
        $this->targetDir = $this->testsWorkingDir . '/vendor-prefixed/acme/lib';
    }

    protected function tearDown(): void
    {
        // The tests' filesystem is rooted at the working directory, which it created on disk.
        if (is_dir($this->testsWorkingDir) && 0 === strpos($this->testsWorkingDir, 'strausstestdir', strrpos($this->testsWorkingDir, '/') + 1)) {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->testsWorkingDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            /** @var \SplFileInfo $entry */
            foreach ($entries as $entry) {
                $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->testsWorkingDir);
        }

        parent::tearDown();
    }

    protected function getSymbols(): DiscoveredSymbols
    {
        $file = new File('vendor/acme/lib/symbols.php', 'acme/lib/symbols.php', 'vendor-prefixed/acme/lib/symbols.php');

        $namespace = new NamespaceSymbol('Acme\Lib', $file);
        $namespace->setDoRename(true);
        $namespace->setLocalReplacement('Prefix\Acme\Lib');
        $class = new ClassSymbol('Acme\Lib\Widget', $file, $namespace);
        $class->setDoRename(true);

        return new DiscoveredSymbols([$namespace, $class]);
    }

    /**
     * A file on disk in the source directory, scanned and planned as the pipeline would have left it.
     */
    protected function getPlannedFile(string $name, string $contents, DiscoveredSymbols $symbols): File
    {
        $this->realFilesystem->write($this->sourceDir . '/' . $name, $contents);

        $file = new File($this->sourceDir . '/' . $name, 'acme/lib/' . $name, $this->targetDir . '/' . $name);

        if ($file->isPhpFile()) {
            $file->setCodeMap((new PhpFileAnalyzer())->analyze($contents));
            (new ChangePlanner())->planInFiles($symbols, [$file]);
        }

        return $file;
    }

    protected function getSut(?FileSystem $filesystem = null, bool $isTargetDirectoryVendor = false): Prefixer
    {
        $config = $this->createMock(PrefixerConfigInterface::class);
        $config->method('isTargetDirectoryVendor')->willReturn($isTargetDirectoryVendor);

        return new Prefixer($config, $filesystem ?? $this->realFilesystem, $this->getLogger());
    }

    /**
     * A filesystem which passes everything to the real one, recording each operation as `operation:filename`.
     *
     * @param string[] $operations
     *
     * @return FileSystem&\Mockery\MockInterface
     */
    protected function getRecordingFilesystem(array &$operations)
    {
        $filesystem = Mockery::mock(FileSystem::class);
        foreach (['read', 'write', 'copy', 'fileExists', 'directoryExists', 'isSymlinked'] as $method) {
            $filesystem->shouldReceive($method)->andReturnUsing(
                function (...$arguments) use ($method, &$operations) {
                    if (in_array($method, ['read', 'write', 'copy'], true)) {
                        $operations[] = $method . ':' . basename((string) $arguments[0]);
                    }
                    return $this->realFilesystem->$method(...$arguments);
                }
            );
        }
        return $filesystem;
    }

    /**
     * @covers ::writeFromSource
     */
    public function test_file_with_planned_edits_is_written_prefixed(): void
    {
        $symbols = $this->getSymbols();
        $file = $this->getPlannedFile('Widget.php', self::SOURCE_CONTENTS, $symbols);
        self::assertNotEmpty($file->getPlannedEdits());

        $wasWritten = $this->getSut()->writeFromSource($symbols, $file);

        self::assertTrue($wasWritten);
        self::assertSame(self::PREFIXED_CONTENTS, $this->realFilesystem->read($this->targetDir . '/Widget.php'));
        self::assertSame(self::SOURCE_CONTENTS, $this->realFilesystem->read($this->sourceDir . '/Widget.php'));

        // As after `replaceInFiles()`: marked updated, the plan consumed, and the map describing the new contents.
        self::assertTrue($file->getDidUpdate());
        self::assertNull($file->getPlannedEdits());
        self::assertNotNull($file->getCodeMap());
        self::assertTrue($file->getCodeMap()->matchesContent(self::PREFIXED_CONTENTS));
    }

    /**
     * Copying then replacing: the source is read once and the target written once. It is never copied, and the
     * target is never read back.
     *
     * @covers ::writeFromSource
     * @covers ::replaceInFile
     * @covers \BrianHenryIE\Strauss\Pipeline\Copier::copy
     * @covers \BrianHenryIE\Strauss\Pipeline\Copier::setFileWriter
     */
    public function test_edited_file_is_written_once_and_unedited_file_is_copied(): void
    {
        $symbols = $this->getSymbols();
        $edited = $this->getPlannedFile('Widget.php', self::SOURCE_CONTENTS, $symbols);
        $unedited = $this->getPlannedFile('config.php', self::UNCHANGED_CONTENTS, $symbols);
        $notPhp = $this->getPlannedFile('readme.md', "# Acme\\Lib\n", $symbols);
        self::assertSame([], $unedited->getPlannedEdits());

        $operations = [];
        $filesystem = $this->getRecordingFilesystem($operations);
        $sut = $this->getSut($filesystem);

        $copier = new Copier(
            new DiscoveredFiles([$edited, $unedited, $notPhp]),
            $this->createMock(CopierConfigInterface::class),
            $filesystem,
            $this->getLogger()
        );
        $copier->setFileWriter(fn(File $file): bool => $sut->writeFromSource($symbols, $file));
        $copier->copy();

        self::assertSame(
            ['read:Widget.php', 'write:Widget.php', 'copy:config.php', 'copy:readme.md'],
            $operations
        );

        $operations = [];
        $sut->replaceInFiles($symbols, [$edited, $unedited, $notPhp]);

        // The unedited PHP file is still checked, as before; the edited one is not touched again.
        self::assertSame(['read:config.php'], $operations);

        self::assertSame(self::PREFIXED_CONTENTS, $this->realFilesystem->read($this->targetDir . '/Widget.php'));
        self::assertSame(self::UNCHANGED_CONTENTS, $this->realFilesystem->read($this->targetDir . '/config.php'));
        self::assertSame("# Acme\\Lib\n", $this->realFilesystem->read($this->targetDir . '/readme.md'));
        self::assertTrue($edited->getDidUpdate());
        self::assertFalse($unedited->getDidUpdate());
    }

    /**
     * The result is the same as copying every file and then replacing in it.
     *
     * @covers ::writeFromSource
     * @covers ::replaceInFiles
     */
    public function test_result_matches_copy_then_replace(): void
    {
        $contents = <<<'EOD'
<?php

namespace Acme\Lib\Tools;

use Acme\Lib\Widget;

/**
 * @param \Acme\Lib\Widget $widget
 */
function describe(Widget $widget): string
{
    return 'Acme\\Lib\\Widget' . \Acme\Lib\Widget::class;
}
EOD;

        $results = [];
        foreach ([true, false] as $useFileWriter) {
            $symbols = $this->getSymbols();
            $file = $this->getPlannedFile('tools.php', $contents, $symbols);
            $sut = $this->getSut();

            $copier = new Copier(
                new DiscoveredFiles([$file]),
                $this->createMock(CopierConfigInterface::class),
                $this->realFilesystem,
                $this->getLogger()
            );
            if ($useFileWriter) {
                $copier->setFileWriter(fn(File $file): bool => $sut->writeFromSource($symbols, $file));
            }
            $copier->copy();
            $sut->replaceInFiles($symbols, [$file]);

            $results[] = [
                $this->realFilesystem->read($this->targetDir . '/tools.php'),
                $file->getDidUpdate(),
                $file->getPlannedEdits(),
                $file->getCodeMap(),
            ];

            $this->realFilesystem->delete($this->targetDir . '/tools.php');
        }

        self::assertStringContainsString('namespace Prefix\Acme\Lib\Tools;', $results[0][0]);
        self::assertEquals($results[1], $results[0]);
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function notWrittenProvider(): array
    {
        return [
            'no edits planned' => ['unedited'],
            'never planned' => ['unplanned'],
            'not to be copied' => ['no-copy'],
            'not to be updated' => ['no-update'],
            'not a PHP file' => ['not-php'],
            'target directory is vendor' => ['target-is-vendor'],
            'source is its own target' => ['same-path'],
        ];
    }

    /**
     * Anything else is left for the copy, and for `replaceInFiles()` afterwards: nothing is written.
     *
     * @dataProvider notWrittenProvider
     *
     * @covers ::writeFromSource
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('notWrittenProvider')]
    public function test_other_files_are_left_to_be_copied(string $case): void
    {
        $symbols = $this->getSymbols();
        $file = $this->getPlannedFile('Widget.php', self::SOURCE_CONTENTS, $symbols);
        $isTargetDirectoryVendor = false;

        switch ($case) {
            case 'unedited':
                $file = $this->getPlannedFile('config.php', self::UNCHANGED_CONTENTS, $symbols);
                break;
            case 'unplanned':
                $file->setPlannedEdits(null);
                break;
            case 'no-copy':
                $file->setDoCopy(false);
                break;
            case 'no-update':
                $file->setDoUpdate(false);
                break;
            case 'not-php':
                $file = $this->getPlannedFile('readme.md', '# Acme\Lib', $symbols);
                break;
            case 'target-is-vendor':
                $isTargetDirectoryVendor = true;
                break;
            case 'same-path':
                $file->setTargetAbsolutePath($file->getSourcePath());
                break;
        }
        $plannedEdits = $file->getPlannedEdits();

        self::assertFalse($this->getSut(null, $isTargetDirectoryVendor)->writeFromSource($symbols, $file));

        self::assertFalse($this->realFilesystem->directoryExists($this->targetDir));
        self::assertSame($plannedEdits, $file->getPlannedEdits(), 'The plan is left for replaceInFiles().');
        self::assertFalse($file->getDidUpdate());
    }

    /**
     * Copying has its own rules for files reached through a symlink, so those are left to it.
     *
     * @covers ::writeFromSource
     */
    public function test_symlinked_source_is_left_to_be_copied(): void
    {
        $this->markTestSkippedOnWindows('symlinks');

        $symbols = $this->getSymbols();
        $realFile = $this->getPlannedFile('Widget.php', self::SOURCE_CONTENTS, $symbols);

        $linkedDir = $this->testsWorkingDir . '/vendor/acme/linked';
        // Where the tests' filesystem really keeps them.
        $osSourceDir = $this->realFilesystem->prefixPath($this->realFilesystem->normalizePath($this->sourceDir));
        $osLinkedDir = $this->realFilesystem->prefixPath($this->realFilesystem->normalizePath($linkedDir));
        self::assertDirectoryExists($osSourceDir);
        symlink($osSourceDir, $osLinkedDir);

        $file = new File($linkedDir . '/Widget.php', 'acme/linked/Widget.php', $this->targetDir . '/Widget.php');
        $file->setCodeMap($realFile->getCodeMap());
        $file->setPlannedEdits($realFile->getPlannedEdits());

        try {
            self::assertFalse($this->getSut()->writeFromSource($symbols, $file));
            self::assertFalse($this->realFilesystem->fileExists($this->targetDir . '/Widget.php'));
            self::assertNotEmpty($file->getPlannedEdits());
        } finally {
            unlink($osLinkedDir);
        }
    }

    /**
     * A source which changed after it was scanned is not written from a plan made for other contents. It is
     * copied, and analysed again when it is updated.
     *
     * @covers ::writeFromSource
     * @covers ::replaceInFiles
     */
    public function test_source_changed_since_scanning_is_copied_then_updated(): void
    {
        $symbols = $this->getSymbols();
        $file = $this->getPlannedFile('Widget.php', self::SOURCE_CONTENTS, $symbols);

        $changedContents = "<?php\n// Changed.\nnamespace Acme\\Lib;\n\nclass Widget\n{\n}\n";
        $this->realFilesystem->write($this->sourceDir . '/Widget.php', $changedContents);

        $sut = $this->getSut();

        self::assertFalse($sut->writeFromSource($symbols, $file));
        self::assertFalse($this->realFilesystem->fileExists($this->targetDir . '/Widget.php'));

        $this->realFilesystem->copy($this->sourceDir . '/Widget.php', $this->targetDir . '/Widget.php');
        $sut->replaceInFiles($symbols, [$file]);

        self::assertSame(
            "<?php\n// Changed.\nnamespace Prefix\\Acme\\Lib;\n\nclass Widget\n{\n}\n",
            $this->realFilesystem->read($this->targetDir . '/Widget.php')
        );
    }
}
