<?php
/**
 * Symbols with the name of a PHP built-in (usually a polyfill) are not recorded, so they are never renamed.
 *
 * Pins how names are compared with the list of built-ins: exactly, by fully qualified name.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Unit\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\Pipeline\FileSymbolScanner;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\FileSymbolScanner
 */
class FileSymbolScannerBuiltInsTest extends TestCase
{
    /**
     * @param array<string, string> $contentsByPath
     */
    protected function scan(array $contentsByPath): DiscoveredSymbols
    {
        $filesystem = Mockery::mock(FileSystem::class);
        $filesystem->shouldReceive('getRelativePath')->andReturnArg(1);
        $filesystem->shouldReceive('read')->andReturnUsing(fn(string $path): string => $contentsByPath[$path]);

        $config = $this->createMock(StraussConfig::class);
        $config->method('getPackagesToPrefix')->willReturn([]);

        $files = array_map(
            fn(string $path): File => new File($path, basename($path), $path),
            array_keys($contentsByPath)
        );
        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn($files);

        $discoveredSymbols = new DiscoveredSymbols();
        (new FileSymbolScanner($config, $discoveredSymbols, $filesystem, $this->getLogger()))
            ->findInFiles($discoveredFiles);

        return $discoveredSymbols;
    }

    /**
     * @return string[]
     */
    protected function getLogMessages(string $level): array
    {
        return array_column($this->getTestLogger()->recordsByLevel[$level] ?? [], 'message');
    }

    /**
     * @covers ::add
     * @covers ::isBuiltInSymbol
     */
    public function test_built_in_function_and_class_are_skipped(): void
    {
        $symbols = $this->scan([
            '/project/vendor/acme/polyfill.php' => <<<'EOD'
<?php
function str_contains($haystack, $needle) {}
function acme_helper() {}
class Stringable {}
class AcmeWidget {}
EOD,
        ]);

        self::assertNull($symbols->getFunction('str_contains'));
        self::assertNull($symbols->getClass('Stringable'));
        self::assertNotNull($symbols->getFunction('acme_helper'));
        self::assertNotNull($symbols->getClass('AcmeWidget'));

        self::assertContains('Skipping built-in symbol Stringable, possible a polyfill.', $this->getLogMessages('debug'));
    }

    /**
     * The built-ins are global: a symbol of the same name in a namespace is the package's own.
     *
     * @covers ::add
     * @covers ::isBuiltInSymbol
     */
    public function test_same_name_in_a_namespace_is_kept(): void
    {
        $symbols = $this->scan([
            '/project/vendor/acme/functions.php' => <<<'EOD'
<?php
namespace Acme;

function str_contains($haystack, $needle) {}
class Stringable {}
EOD,
        ]);

        self::assertNotNull($symbols->getFunction('Acme\str_contains'));
        self::assertNotNull($symbols->getClass('Acme\Stringable'));
    }

    /**
     * Names are compared exactly, although PHP's function and class names are not case-sensitive: a declaration
     * in another case is not recognised as the built-in.
     *
     * @covers ::add
     * @covers ::isBuiltInSymbol
     */
    public function test_names_are_compared_case_sensitively(): void
    {
        $symbols = $this->scan([
            '/project/vendor/acme/polyfill.php' => <<<'EOD'
<?php
function STR_CONTAINS($haystack, $needle) {}
class stringable {}
EOD,
        ]);

        self::assertNotNull($symbols->getFunction('STR_CONTAINS'));
        self::assertNotNull($symbols->getClass('stringable'));
    }

    /**
     * A symbol found in several files is announced as "new" once.
     *
     * @covers ::add
     */
    public function test_a_symbol_is_logged_as_new_once(): void
    {
        $contents = "<?php\nnamespace Acme\\Lib;\nfunction helper() {}\n";

        $symbols = $this->scan([
            '/project/vendor/acme/one.php' => $contents,
            '/project/vendor/acme/two.php' => $contents,
            '/project/vendor/acme/three.php' => $contents,
        ]);

        self::assertNotNull($symbols->getFunction('Acme\Lib\helper'));

        $infoMessages = $this->getLogMessages('info');

        self::assertCount(1, array_intersect($infoMessages, ['Found new function:::Acme\Lib\helper']));
        self::assertCount(1, array_intersect($infoMessages, ['Found new namespace:::Acme\Lib']));
        self::assertNotContains('Found function:::Acme\Lib\helper', $infoMessages);
        self::assertNotContains('Found namespace:::Acme\Lib', $infoMessages);
    }

    /**
     * Files of a package which is not being prefixed are scanned but marked not to be prefixed.
     *
     * @covers ::findInFiles
     */
    public function test_files_of_packages_not_being_prefixed_are_not_prefixed(): void
    {
        $filesystem = Mockery::mock(FileSystem::class);
        $filesystem->shouldReceive('read')->andReturn("<?php\nnamespace Acme\\Lib;\nclass Widget {}\n");

        $prefixed = $this->createMock(ComposerPackage::class);
        $prefixed->method('getPackageName')->willReturn('acme/prefixed');
        $prefixed->method('getPackageAbsolutePath')->willReturn('/v/acme/prefixed');
        $notPrefixed = $this->createMock(ComposerPackage::class);
        $notPrefixed->method('getPackageName')->willReturn('acme/not-prefixed');
        $notPrefixed->method('getPackageAbsolutePath')->willReturn('/v/acme/not-prefixed');

        $config = $this->createMock(StraussConfig::class);
        $config->method('getPackagesToPrefix')->willReturn(['acme/prefixed' => $prefixed]);

        $prefixedFile = new FileWithDependency($prefixed, 'acme/prefixed/a.php', '/v/acme/prefixed/a.php', '/t/acme/prefixed/a.php');
        $notPrefixedFile = new FileWithDependency($notPrefixed, 'acme/not-prefixed/b.php', '/v/acme/not-prefixed/b.php', '/t/acme/not-prefixed/b.php');

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$prefixedFile, $notPrefixedFile]);

        (new FileSymbolScanner($config, new DiscoveredSymbols(), $filesystem, $this->getLogger()))
            ->findInFiles($discoveredFiles);

        self::assertTrue($prefixedFile->isDoPrefix());
        self::assertFalse($notPrefixedFile->isDoPrefix());
        self::assertNotNull($notPrefixedFile->getCodeMap());
    }
}
