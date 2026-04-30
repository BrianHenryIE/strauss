<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\FileSymbolScannerConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use Mockery;

/**
 * @covers \BrianHenryIE\Strauss\Pipeline\FileSymbolScanner
 */
class FileSymbolScannerPerformanceTest extends TestCase
{
    public function test_parser_reuse_does_not_leak_namespaces_across_files(): void
    {
        $firstContents = <<<'EOD'
<?php
namespace FirstNs {
    class FirstNamespaced {}
}
namespace {
    class FirstGlobal {}
}
EOD;

        $secondContents = <<<'EOD'
<?php
namespace SecondNs {
    class SecondNamespaced {}
}
namespace {
    class SecondGlobal {}
}
EOD;

        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->twice()->andReturn($firstContents, $secondContents);
        $filesystemReaderMock->expects('getRelativePath')->twice()->andReturn('vendor/vendor-a/one.php', 'vendor/vendor-a/two.php');

        $config = $this->createMock(FileSymbolScannerConfigInterface::class);
        $config->method('getProjectDirectory')->willReturn('/project');
        $config->method('getPackagesToPrefix')->willReturn([]);
        $discoveredSymbols = new DiscoveredSymbols();
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $fileOne = Mockery::mock(File::class);
        $fileOne->shouldReceive('isPhpFile')->andReturnTrue();
        $fileOne->shouldReceive('getTargetRelativePath');
        $fileOne->shouldReceive('getDependency');
        $fileOne->shouldReceive('addDiscoveredSymbol');
        $fileOne->shouldReceive('getSourcePath')->andReturn('/a/path-one.php');

        $fileTwo = Mockery::mock(File::class);
        $fileTwo->shouldReceive('isPhpFile')->andReturnTrue();
        $fileTwo->shouldReceive('getTargetRelativePath');
        $fileTwo->shouldReceive('getDependency');
        $fileTwo->shouldReceive('addDiscoveredSymbol');
        $fileTwo->shouldReceive('getSourcePath')->andReturn('/a/path-two.php');

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->once()->andReturn([$fileOne, $fileTwo]);

        $result = $sut->findInFiles($discoveredFiles);

        self::assertArrayHasKey('FirstNs', $result->getDiscoveredNamespaces());
        self::assertArrayHasKey('SecondNs', $result->getDiscoveredNamespaces());
        self::assertContains('FirstGlobal', $result->getDiscoveredClasses());
        self::assertContains('SecondGlobal', $result->getDiscoveredClasses());
    }

    public function test_string_containing_namespace_keyword_is_ignored(): void
    {
        $contents = <<<'EOD'
<?php
$statement = "namespace Fake\Namespace;";

function keep_global() {
    return true;
}
EOD;

        $result = $this->scanContentForSymbols($contents);

        self::assertEmpty($result->getDiscoveredNamespaces());
        self::assertArrayHasKey('keep_global', $result->getDiscoveredFunctions());
    }

    public function test_namespace_operator_does_not_create_additional_namespace(): void
    {
        $contents = <<<'EOD'
<?php
namespace Demo;

function read_length(string $input): int {
    return namespace\strlen($input);
}
EOD;

        $result = $this->scanContentForSymbols($contents);

        self::assertArrayHasKey('Demo', $result->getDiscoveredNamespaces());
        self::assertArrayNotHasKey('strlen', $result->getDiscoveredNamespaces());
        self::assertArrayHasKey('Demo\\read_length', $result->getDiscoveredFunctions());
    }

    /**
     * @dataProvider namespaceTokenContextProvider
     *
     * @param string[] $expectedNamespaces
     * @param string[] $absentNamespaces
     * @param string[] $expectedFunctions
     */
    public function test_namespace_token_detection_ignores_non_declaration_contexts(
        string $contents,
        array $expectedNamespaces,
        array $absentNamespaces,
        array $expectedFunctions
    ): void {
        $result = $this->scanContentForSymbols($contents);

        foreach ($expectedNamespaces as $namespace) {
            self::assertArrayHasKey($namespace, $result->getDiscoveredNamespaces());
        }
        foreach ($absentNamespaces as $namespace) {
            self::assertArrayNotHasKey($namespace, $result->getDiscoveredNamespaces());
        }
        foreach ($expectedFunctions as $function) {
            self::assertArrayHasKey($function, $result->getDiscoveredFunctions());
        }
    }

    /**
     * @return array<string,array{0:string,1:array<int,string>,2:array<int,string>,3:array<int,string>}>
     */
    public static function namespaceTokenContextProvider(): array
    {
        return [
            'heredoc and nowdoc text' => [
                <<<'PHP'
<?php
$heredoc = <<<TXT
namespace Fake\Heredoc;
TXT;
$nowdoc = <<<'TXT'
namespace Fake\Nowdoc;
TXT;
function keep_template_text() {
    return true;
}
PHP,
                [],
                ['Fake\\Heredoc', 'Fake\\Nowdoc'],
                ['keep_template_text'],
            ],
            'attribute argument string' => [
                <<<'PHP'
<?php
namespace AttrDemo;
#[Marker("namespace Fake\Attribute;")]
class WithAttribute {}
function attr_helper() {
    return true;
}
PHP,
                ['AttrDemo'],
                ['Fake\\Attribute'],
                ['AttrDemo\\attr_helper'],
            ],
            'declare before namespace' => [
                <<<'PHP'
<?php
declare(strict_types=1);
namespace DeclaredDemo;
function declared_helper() {
    return true;
}
PHP,
                ['DeclaredDemo'],
                [],
                ['DeclaredDemo\\declared_helper'],
            ],
        ];
    }

    public function test_dependency_file_outside_packages_to_prefix_sets_do_prefix_false(): void
    {
        $contents = <<<'EOD'
<?php
function scan_me() {
    return true;
}
EOD;

        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->never();

        $config = $this->createMock(FileSymbolScannerConfigInterface::class);
        $config->method('getProjectDirectory')->willReturn('/project');
        $config->method('getPackagesToPrefix')->willReturn([
            'vendor/vendor-b' => $this->createMock(ComposerPackage::class),
        ]);

        $discoveredSymbols = new DiscoveredSymbols();
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        /** @var ComposerPackage&\Mockery\MockInterface $dependency */
        $dependency = Mockery::mock(ComposerPackage::class);
        $dependency->shouldReceive('getPackageName')->andReturn('vendor/vendor-a');
        $dependency->shouldReceive('getPackageAbsolutePath')->andReturn('/project/vendor/vendor-a/');
        $dependency->shouldReceive('addFile')->once();

        $file = new FileWithDependency(
            $dependency,
            'vendor/vendor-a/file.php',
            '/project/vendor/vendor-a/file.php'
        );
        $file->setDoPrefix(true);

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $result = $sut->findInFiles($discoveredFiles);

        self::assertArrayHasKey('scan_me', $result->getDiscoveredFunctions());
        self::assertFalse($file->isDoPrefix());
    }

    public function test_dependency_file_inside_packages_to_prefix_does_not_set_do_prefix_false(): void
    {
        $contents = <<<'EOD'
<?php
function scan_me_too() {
    return true;
}
EOD;

        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->never();

        /** @var ComposerPackage&\Mockery\MockInterface $dependency */
        $dependency = Mockery::mock(ComposerPackage::class);
        $dependency->shouldReceive('getPackageName')->andReturn('vendor/vendor-a');
        $dependency->shouldReceive('getPackageAbsolutePath')->andReturn('/project/vendor/vendor-a/');
        $dependency->shouldReceive('addFile')->once();

        $config = $this->createMock(FileSymbolScannerConfigInterface::class);
        $config->method('getProjectDirectory')->willReturn('/project');
        $config->method('getPackagesToPrefix')->willReturn([
            'vendor/vendor-a' => $dependency,
        ]);

        $discoveredSymbols = new DiscoveredSymbols();
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = new FileWithDependency(
            $dependency,
            'vendor/vendor-a/file.php',
            '/project/vendor/vendor-a/file.php'
        );
        $file->setDoPrefix(true);

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $result = $sut->findInFiles($discoveredFiles);

        self::assertArrayHasKey('scan_me_too', $result->getDiscoveredFunctions());
        self::assertTrue($file->isDoPrefix());
    }

    private function scanContentForSymbols(string $contents): DiscoveredSymbols
    {
        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $discoveredSymbols = new DiscoveredSymbols();
        $config = $this->createMock(FileSymbolScannerConfigInterface::class);
        $config->method('getProjectDirectory')->willReturn('/project');
        $config->method('getPackagesToPrefix')->willReturn([]);
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = Mockery::mock(File::class);
        $file->shouldReceive('isPhpFile')->andReturnTrue();
        $file->shouldReceive('getTargetRelativePath');
        $file->shouldReceive('getDependency');
        $file->shouldReceive('addDiscoveredSymbol');
        $file->shouldReceive('getSourcePath')->andReturn('/a/path');

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        return $sut->findInFiles($discoveredFiles);
    }
}
