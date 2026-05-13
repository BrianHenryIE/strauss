<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\FileSymbolScannerConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\IntegrationTestCase;
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
        $contentsOne = <<<'EOD'
<?php
namespace FirstNs {
    class FirstNamespaced {}
}
namespace {
    class FirstGlobal {}
}
EOD;

        $contentsTwo = <<<'EOD'
<?php
namespace SecondNs {
    class SecondNamespaced {}
}
namespace {
    class SecondGlobal {}
}
EOD;

        $config = $this->createMock(FileSymbolScannerConfigInterface::class);
        $config->method('getProjectAbsolutePath')->willReturn('project');
        $config->method('getPackagesToPrefix')->willReturn([]);
        $discoveredSymbols = new DiscoveredSymbols();
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $this->getFileSystem());

        $fileOne = new File(
            'project/vendor/a/package/path-one.php',
            'a/package/path-one.php',
            'project/vendor-prefixed/a/package/path-one.php',
        );
        $fileTwo = new File(
            'project/vendor/a/package/path-two.php',
            'a/package/path-two.php',
            'project/vendor-prefixed/a/package/path-two.php',
        );

        $discoveredFiles = new DiscoveredFiles([$fileOne, $fileTwo]);

        $this->getFileSystem()->write($fileOne->getSourcePath(), $contentsOne);
        $this->getFileSystem()->write($fileTwo->getSourcePath(), $contentsTwo);

        $result = $sut->findInFiles($discoveredFiles);

        $this->assertArrayHasKey('FirstNs', $result->getDiscoveredNamespaces()->toArray());
        $this->assertArrayHasKey('SecondNs', $result->getDiscoveredNamespaces()->toArray());
        $this->assertContains('FirstGlobal', $result->getDiscoveredClasses()->originalLocalNames());
        $this->assertContains('SecondGlobal', $result->getDiscoveredClasses()->originalLocalNames());
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

        $this->assertEmpty($result->getDiscoveredNamespaces()->toArray());
        $this->assertArrayHasKey('keep_global', $result->getDiscoveredFunctions()->toArray());
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

        $this->assertArrayHasKey('Demo', $result->getDiscoveredNamespaces()->toArray());
        $this->assertArrayNotHasKey('strlen', $result->getDiscoveredNamespaces()->toArray());
        $this->assertArrayHasKey('Demo\\read_length', $result->getDiscoveredFunctions()->toArray());
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
            $this->assertArrayHasKey($namespace, $result->getDiscoveredNamespaces()->toArray());
        }
        foreach ($absentNamespaces as $namespace) {
            $this->assertArrayNotHasKey($namespace, $result->getDiscoveredNamespaces()->toArray());
        }
        foreach ($expectedFunctions as $function) {
            $this->assertArrayHasKey($function, $result->getDiscoveredFunctions()->toArray());
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

        $config = $this->createMock(FileSymbolScannerConfigInterface::class);
        $config->method('getProjectAbsolutePath')->willReturn('project');
        $config->method('getPackagesToPrefix')->willReturn([
            'vendor/vendor-b' => $this->createMock(ComposerPackage::class),
        ]);

        $discoveredSymbols = new DiscoveredSymbols();
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $this->getFileSystem());

        /** @var ComposerPackage&\Mockery\MockInterface $dependency */
        $dependency = Mockery::mock(ComposerPackage::class);
        $dependency->shouldReceive('getPackageName')->andReturn('vendor/vendor-a');
        $dependency->shouldReceive('getPackageAbsolutePath')->andReturn('project/vendor/vendor-a');
        $dependency->shouldReceive('addFile')->once();

        $file = new FileWithDependency(
            $dependency,
            'vendor/vendor-a/file.php',
            'project/vendor/vendor-a/file.php',
            'vendor-prefixed/vendor-a/file.php'
        );
        $file->setDoPrefix(true);

        $this->getFileSystem()->write($file->getSourcePath(), $contents);

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $result = $sut->findInFiles($discoveredFiles);

        $this->assertArrayHasKey('scan_me', $result->getDiscoveredFunctions()->toArray());
        $this->assertFalse($file->isDoPrefix());
    }

    public function test_dependency_file_inside_packages_to_prefix_does_not_set_do_prefix_false(): void
    {
        $contents = <<<'EOD'
<?php
function scan_me_too() {
    return true;
}
EOD;

        /** @var ComposerPackage&\Mockery\MockInterface $dependency */
        $dependency = Mockery::mock(ComposerPackage::class);
        $dependency->shouldReceive('getPackageName')->andReturn('vendor/vendor-a');
        $dependency->shouldReceive('getPackageAbsolutePath')->andReturn('/project/vendor/vendor-a/');
        $dependency->shouldReceive('addFile')->once();

        $config = $this->createMock(FileSymbolScannerConfigInterface::class);
        $config->method('getProjectAbsolutePath')->willReturn('/project');
        $config->method('getPackagesToPrefix')->willReturn([
            'vendor/vendor-a' => $dependency,
        ]);

        $discoveredSymbols = new DiscoveredSymbols();
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $this->getFileSystem());

        $file = new FileWithDependency(
            $dependency,
            'vendor/vendor-a/file.php',
            '/project/vendor/vendor-a/file.php',
            'vendor-prefixed/vendor-a/file.php'
        );
        $file->setDoPrefix(true);

        $this->getFileSystem()->write($file->getSourcePath(), $contents);

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $result = $sut->findInFiles($discoveredFiles);

        $this->assertArrayHasKey('scan_me_too', $result->getDiscoveredFunctions()->toArray());
        $this->assertTrue($file->isDoPrefix());
    }

    private function scanContentForSymbols(string $contents): DiscoveredSymbols
    {
        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $discoveredSymbols = new DiscoveredSymbols();
        $config = $this->createMock(FileSymbolScannerConfigInterface::class);
        $config->method('getProjectAbsolutePath')->willReturn('/project');
        $config->method('getPackagesToPrefix')->willReturn([]);
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        return $sut->findInFiles($discoveredFiles);
    }
}
