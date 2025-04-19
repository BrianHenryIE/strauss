<?php

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\ClassSymbol;
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

        $fileMock = Mockery::mock(File::class);
        $fileMock->expects('getSourcePath')->once()->andReturn('/path/to/file.php');
        $fileMock->expects('addDiscoveredSymbol')->once();

        $sut = new ClassSymbol('MyClass', $fileMock);

        $this->assertEquals('MyClass', $sut->getOriginalSymbol());
    }

    /**
     * @covers ::addSourceFile
     * @covers ::getSourceFiles
     */
    public function testMultipleSourceFiles(): void
    {

        $fileMock1 = Mockery::mock(File::class);
        $fileMock1->expects('getSourcePath')->once()->andReturn('/path/to/file1.php');
        $fileMock1->expects('addDiscoveredSymbol')->once();

        $fileMock2 = Mockery::mock(File::class);
        $fileMock2->expects('getSourcePath')->once()->andReturn('/path/to/file2.php');

        $sut = new ClassSymbol('MyClass', $fileMock1);
        $sut->addSourceFile($fileMock2);

        $result = $sut->getSourceFiles();

        $this->assertCount(2, $result);
    }

    /**
     * @covers ::setReplacement
     * @covers ::getReplacement
     */
    public function testReplacement(): void
    {

        $fileMock = Mockery::mock(File::class);
        $fileMock->expects('getSourcePath')->once()->andReturn('/path/to/file.php');
        $fileMock->expects('addDiscoveredSymbol')->once();

        $sut = new ClassSymbol('MyClass', $fileMock);

        $sut->setReplacement('MyClassRenamed');

        $this->assertEquals('MyClassRenamed', $sut->getReplacement());
    }
}
