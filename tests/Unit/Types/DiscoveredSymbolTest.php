<?php

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\TestCase;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Types\DiscoveredSymbol
 */
class DiscoveredSymbolTest extends TestCase
{

    /**
     * @covers ::__construct
     * @covers ::getOriginalSymbol
     */
    public function testCreate(): void
    {
        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $sut = new ClassSymbol('MyClass', $file);

        $this->assertEquals('MyClass', $sut->getOriginalSymbol());
    }

    /**
     * @covers ::addSourceFile
     * @covers ::getSourceFiles
     */
    public function testMultipleSourceFiles(): void
    {

        $file1 = new File(
            'vendor/package1/name/src/file1.php',
            'package2/name/src/file1.php',
            'vendor-prefixed/package2/name/src/file1.php',
        );
        $file2 = new File(
            'vendor/package2/name/src/file2.php',
            'package2/name/src/file2.php',
            'vendor-prefixed/package2/name/src/file2.php',
        );

        $sut = new ClassSymbol('MyClass', $file1);
        $sut->addSourceFile($file2);

        $result = $sut->getSourceFiles();

        $this->assertCount(2, $result);
    }

    /**
     * @covers ::setLocalReplacement
     * @covers ::getLocalReplacement
     */
    public function testReplacement(): void
    {
        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $sut = new ClassSymbol('MyClass', $file);

        $sut->setLocalReplacement('MyClassRenamed');

        $this->assertEquals('MyClassRenamed', $sut->getLocalReplacement());
    }

    /**
     * @covers ::getPackages()
     */
    public function testFilterDuplicatePackages(): void
    {
        $composerJson = $this->getFixturesFilesystem()->read(__DIR__ . '/../Composer/projectcomposerpackage-test-1.json');
        $composerJsonArray = json_decode($composerJson, true);
        $dependency = ComposerPackage::fromComposerJsonArray($composerJsonArray);

        $file1 = new FileWithDependency(
            $dependency,
            'vendor/path/to/file1.php',
            'path/to/file1.php',
            'vendor-prefixed/path/to/file1.php',
        );

        $file2 = new FileWithDependency(
            $dependency,
            'vendor/path/to/file2.php',
            'path/to/file2.php',
            'vendor-prefixed/path/to/file2.php',
        );

        $classSymbol = new ClassSymbol('myClass', $file1);
        $classSymbol->addSourceFile($file2);

        $this->assertCount(1, $classSymbol->getPackages());
    }
}
