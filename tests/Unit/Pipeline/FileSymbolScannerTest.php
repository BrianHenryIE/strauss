<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Config\FileSymbolScannerConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\FileSymbolScanner
 */
class FileSymbolScannerTest extends TestCase
{

    // PREG_BACKTRACK_LIMIT_ERROR

    // Single implied global namespace.
    // Single named namespace.
    // Single explicit global namespace.
    // Multiple namespaces.

    /**
     * @return File|(File&Mockery\MockInterface&object&Mockery\LegacyMockInterface)|(Mockery\MockInterface&object&Mockery\LegacyMockInterface)
     */
    protected function getFile(bool $expectAddDiscoveredSymbol = true)
    {

        $file = Mockery::mock(File::class);
        $file->shouldReceive('isPhpFile')->andReturnTrue();
        $file->shouldReceive('getTargetRelativePath');
        $file->shouldReceive('getDependency');
        if ($expectAddDiscoveredSymbol) {
            $file->shouldReceive('addDiscoveredSymbol')->zeroOrMoreTimes();
        }
        $file->shouldReceive('getSourcePath')->andReturn('/a/path');
        $file->shouldReceive('getTargetAbsolutePath')->andReturn('another/path');
        $file->shouldReceive('getVendorRelativePath')->andReturn('another/path');
        $file->shouldReceive('isDoCopy')->andReturnTrue();

        return $file;
    }

    /**
     * @covers ::findInFiles
     */
    public function testSingleNamespace(): void
    {

        $contents = <<<'EOD'
<?php
namespace MyNamespace;

class MyClass {
}
EOD;

        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $config = $this->createMock(StraussConfig::class);
        $config->method('getNamespacePrefix')->willReturn('Prefix');

        $discoveredSymbols = new DiscoveredSymbols();
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = $this->getFile();

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $sut->findInFiles($discoveredFiles);

        $this->assertArrayHasKey('MyNamespace', $discoveredSymbols->getDiscoveredNamespaces()->toArray());
//        $this->assertContains('Prefix\MyNamespace', $sut->getDiscoveredNamespaces());

        $this->assertNotContains('MyClass', $discoveredSymbols->getDiscoveredClasses());
    }

    /**
     * @covers ::findInFiles
     */
    public function testGlobalNamespace(): void
    {

        $contents = <<<'EOD'
<?php
namespace {
    class MyClass {
    }
}
EOD;

        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $config = $this->createMock(StraussConfig::class);

        $discoveredSymbols = new DiscoveredSymbols();

        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = $this->getFile();

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $sut->findInFiles($discoveredFiles);
        $this->assertArrayHasKey('MyClass', $discoveredSymbols->getDiscoveredClasses()->toArray());
    }

    /**
     * @covers ::findInFiles
     */
    public function testMultipleNamespace(): void
    {

        $contents = <<<'EOD'
<?php
namespace MyNamespace {
    class MyClass {
    }
}
namespace {
    class MyClass {
    }
}
EOD;

        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $discoveredSymbols = new DiscoveredSymbols();

        $config = $this->createMock(StraussConfig::class);
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = $this->getFile();

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $sut->findInFiles($discoveredFiles);

        $this->assertArrayHasKey('MyNamespace', $discoveredSymbols->getDiscoveredNamespaces()->toArray());

        $this->assertArrayHasKey('MyClass', $discoveredSymbols->getDiscoveredClasses()->toArray());
    }

    /**
     * @covers ::findInFiles
     */
    public function testMultipleNamespaceGlobalFirst(): void
    {

        $contents = <<<'EOD'
<?php

namespace {
    class MyClass {
    }
}
namespace MyNamespace {
    class MyOtherClass {
    }
}
EOD;

        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $discoveredSymbols = new DiscoveredSymbols();

        $config = $this->createMock(StraussConfig::class);
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = $this->getFile();

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $sut->findInFiles($discoveredFiles);

        $this->assertArrayHasKey('MyNamespace', $discoveredSymbols->getDiscoveredNamespaces()->toArray());

        $this->assertArrayHasKey('MyClass', $discoveredSymbols->getDiscoveredClasses()->toArray());
        $this->assertArrayNotHasKey('MyOtherClass', $discoveredSymbols->getDiscoveredClasses()->toArray());
    }

    /**
     * @covers ::findInFiles
     */
    public function testItDoesNotFindNamespaceInComment(): void
    {

        $contents = <<<'EOD'
<?php

/**
 * @todo Rewrite to use Interchange objects
 */
class HTMLPurifier_Printer_ConfigForm extends HTMLPurifier_Printer
{

    /**
     * Returns HTML output for a configuration form
     * @param HTMLPurifier_Config|array $config Configuration object of current form state, or an array
     *        where [0] has an HTML namespace and [1] is being rendered.
     * @param array|bool $allowed Optional namespace(s) and directives to restrict form to.
     * @param bool $render_controls
     * @return string
     */
    public function render($config, $allowed = true, $render_controls = true)
    {

        // blah

        return $ret;
    }

}

// vim: et sw=4 sts=4
EOD;

        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $discoveredSymbols = new DiscoveredSymbols();

        $config = $this->createMock(StraussConfig::class);
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        try {
            $file = $this->getFile();

            $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
            $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

            $discoveredSymbols = $sut->findInFiles($discoveredFiles);
        } catch (\PHPUnit\Framework\Error\Warning $e) {
            self::fail('Should not throw an exception');
        }

        $this->assertEmpty($discoveredSymbols->getDiscoveredNamespaces()->toArray());
    }

    /**
     * @covers ::findInFiles
     */
    public function testMultipleClasses(): void
    {

        $contents = <<<'EOD'
<?php
class MyClass {
}
class MyOtherClass {

}
EOD;

        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $discoveredSymbols = new DiscoveredSymbols();

        $config = $this->createMock(StraussConfig::class);
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = $this->getFile();

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $sut->findInFiles($discoveredFiles);

        $this->assertArrayHasKey('MyClass', $discoveredSymbols->getDiscoveredClasses()->toArray());
        $this->assertArrayNotHasKey('MyOtherClass', $discoveredSymbols->getDiscoveredClasses());
    }

    /**
     * @covers ::findInFiles
     */
    public function test_it_does_not_treat_comments_as_classes(): void
    {
        $contents = "
        // A class as good as any.
        class Whatever {

        }
        ";


        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $discoveredSymbols = new DiscoveredSymbols();

        $config = $this->createMock(StraussConfig::class);
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = $this->getFile();

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $sut->findInFiles($discoveredFiles);

        $this->assertArrayNotHasKey('as', $discoveredSymbols->getDiscoveredClasses()->toArray());
        $this->assertArrayHasKey('Whatever', $discoveredSymbols->getDiscoveredClasses()->toArray());
    }

    /**
     * @covers ::findInFiles
     */
    public function test_it_does_not_treat_multiline_comments_as_classes(): void
    {
        $contents = "
    	 /**
    	  * A class as good as any; class as.
    	  */
    	class Whatever {
    	}
    	";


        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $discoveredSymbols = new DiscoveredSymbols();

        $config = $this->createMock(StraussConfig::class);
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = $this->getFile();

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $sut->findInFiles($discoveredFiles);

        $this->assertArrayNotHasKey('as', $discoveredSymbols->getDiscoveredClasses()->toArray());
        $this->assertArrayHasKey('Whatever', $discoveredSymbols->getDiscoveredClasses()->toArray());
    }

    /**
     * This worked without adding the expected regex:
     *
     * // \s*\\/?\\*{2,}[^\n]* |                        # Skip multiline comment bodies
     *
     * @covers ::findInFiles
     */
    public function test_it_does_not_treat_multiline_comments_opening_line_as_classes(): void
    {
        $contents = "
    	 /** A class as good as any; class as.
    	  *
    	  */
    	class Whatever {
    	}
    	";


        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $discoveredSymbols = new DiscoveredSymbols();

        $config = $this->createMock(StraussConfig::class);
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = $this->getFile();

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $sut->findInFiles($discoveredFiles);

        $this->assertArrayNotHasKey('as', $discoveredSymbols->getDiscoveredClasses()->toArray());
        $this->assertArrayHasKey('Whatever', $discoveredSymbols->getDiscoveredClasses()->toArray());
    }

    /**
     * @covers ::findInFiles
     */
    public function test_it_does_not_treat_multiline_comments_on_one_line_as_classes(): void
    {
        $contents = "
    	 /** A class as good as any; class as. */ class Whatever_Trevor {
    	}
    	";


        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $discoveredSymbols = new DiscoveredSymbols();

        $config = $this->createMock(StraussConfig::class);
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = $this->getFile();

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $sut->findInFiles($discoveredFiles);

        $this->assertArrayNotHasKey('as', $discoveredSymbols->getDiscoveredClasses()->toArray());
        $this->assertArrayHasKey('Whatever_Trevor', $discoveredSymbols->getDiscoveredClasses()->toArray());
    }

    /**
     * If someone were to put a semicolon in the comment it would mess with the previous fix.
     *
     * @covers ::findInFiles
     */
    public function test_it_does_not_treat_comments_with_semicolons_as_classes(): void
    {
        $contents = "
    	// A class as good as any; class as versatile as any.
    	class Whatever_Ever {

    	}
    	";

        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $discoveredSymbols = new DiscoveredSymbols();

        $config = $this->createMock(StraussConfig::class);
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = $this->getFile();

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $sut->findInFiles($discoveredFiles);

        $this->assertArrayNotHasKey('as', $discoveredSymbols->getDiscoveredClasses()->toArray());
        $this->assertArrayHasKey('Whatever_Ever', $discoveredSymbols->getDiscoveredClasses()->toArray());
    }

    /**
     * @covers ::findInFiles
     */
    public function test_it_parses_classes_after_semicolon(): void
    {

        $contents = "
	    \$myvar = 123; class Pear { };
	    ";

        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $discoveredSymbols = new DiscoveredSymbols();

        $config = $this->createMock(StraussConfig::class);
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = $this->getFile();

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $result = $sut->findInFiles($discoveredFiles);

        $this->assertArrayHasKey('Pear', $result->getDiscoveredClasses()->toArray());
    }

    /**
     * @covers ::findInFiles
     */
    public function test_it_parses_classes_followed_by_comment(): void
    {

        $contents = <<<'EOD'
                    <?php
                    class WP_Dependency_Installer {
                    	/**
                    	 *
                    	 */
                    }
                    EOD;

        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $discoveredSymbols = new DiscoveredSymbols();

        $config = $this->createMock(StraussConfig::class);
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = $this->getFile();

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $result = $sut->findInFiles($discoveredFiles);

        $this->assertArrayHasKey('WP_Dependency_Installer', $result->getDiscoveredClasses()->toArray());
    }


    /**
     * It's possible to have multiple namespaces inside one file.
     *
     * To have two classes in one file, one in a namespace and the other not, the global namespace needs to be explicit.
     *
     * @covers ::findInFiles
     */
    public function it_does_not_replace_inside_named_namespace_but_does_inside_explicit_global_namespace_a(): void
    {

        $contents = "
        <?php
		namespace My_Project {
			class A_Class { }
		}
		namespace {
			class B_Class { }
		}
		";


        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);

        $discoveredSymbols = new DiscoveredSymbols();

        $config = $this->createMock(StraussConfig::class);
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = $this->getFile();

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $result = $sut->findInFiles($discoveredFiles);

        $this->assertNotContains('A_Class', $result->getDiscoveredClasses());
        $this->assertContains('B_Class', $result->getDiscoveredClasses());
    }

    /**
     * @covers ::findInFiles
     */
    public function testExcludePackagesFromPrefix(): void
    {

        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn('');
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $config = $this->createMock(StraussConfig::class);
        $config->method('getExcludePackagesFromPrefixing')->willReturn(
            array('brianhenryie/pdfhelpers')
        );

        $composerPackage = $this->createMock(ComposerPackage::class);
        $composerPackage->method('getPackageName')->willReturn('brianhenryie/pdfhelpers');

        $file = $this->getFile(false);

        $file->shouldReceive('addDiscoveredSymbol')
             ->withArgs(fn($v) => $v instanceof NamespaceSymbol && $v->isGlobal())
             ->once();

        $files = Mockery::mock(DiscoveredFiles::class)->makePartial();
        $files->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = new DiscoveredSymbols();

        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);
        $result = $sut->findInFiles($files);

        $this->assertEmpty($result->getDiscoveredNamespaces()->toArray());
    }

    /**
     * @covers ::findInFiles
     */
    public function testExcludeFilePatternsFromPrefix(): void
    {
        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn('');
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $config = $this->createMock(StraussConfig::class);
        $config->method('getExcludeFilePatternsFromPrefixing')->willReturn(
            array('/to/')
        );

        $composerPackage = $this->createMock(ComposerPackage::class);
        $composerPackage->method('getPackageName')->willReturn('brianhenryie/pdfhelpers');

        $file = $this->getFile(false);

        $file->shouldReceive('addDiscoveredSymbol')
             ->withArgs(fn($v) => $v instanceof NamespaceSymbol && $v->isGlobal())
             ->once();

        $files = Mockery::mock(DiscoveredFiles::class)->makePartial();
        $files->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = new DiscoveredSymbols();

        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);
        $result = $sut->findInFiles($files);

        $this->assertEmpty($result->getDiscoveredNamespaces()->toArray());
    }

    /**
     * Test custom replacements
     *
     * @covers ::findInFiles
     */
    public function testNamespaceReplacementPatterns(): void
    {
        $contents = "
        <?php
		namespace BrianHenryIE\PdfHelpers {
			class A_Class { }
		}
		";

        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $config = $this->createMock(StraussConfig::class);
        $config->method('getNamespacePrefix')->willReturn('BrianHenryIE\Prefix');
        $config->method('getNamespaceReplacementPatterns')->willReturn(
            array('/BrianHenryIE\\\\(PdfHelpers)/'=>'BrianHenryIE\\Prefix\\\\$1')
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = $this->getFile();

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $result = $sut->findInFiles($discoveredFiles);

        $this->assertArrayHasKey('BrianHenryIE\PdfHelpers', $result->getDiscoveredNamespaces()->toArray());
//        $this->assertContains('BrianHenryIE\Prefix\PdfHelpers', $fileScanner->getDiscoveredNamespaces());
//        $this->assertNotContains('BrianHenryIE\Prefix\BrianHenryIE\PdfHelpers', $fileScanner->getDiscoveredNamespaces());
    }

    /**
     * @see https://github.com/BrianHenryIE/strauss/issues/19
     *
     * @covers ::findInFiles
     */
    public function testPhraseClassObjectIsNotMistaken(): void
    {

        $contents = <<<'EOD'
<?php

class TCPDF_STATIC
{

    /**
     * Creates a copy of a class object
     * @param $object (object) class object to be cloned
     * @return cloned object
     * @since 4.5.029 (2009-03-19)
     * @public static
     */
    public static function objclone($object)
    {
        if (($object instanceof Imagick) and (version_compare(phpversion('imagick'), '3.0.1') !== 1)) {
            // on the versions after 3.0.1 the clone() method was deprecated in favour of clone keyword
            return @$object->clone();
        }
        return @clone($object);
    }
}
EOD;

        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $discoveredSymbols = new DiscoveredSymbols();

        $config = $this->createMock(StraussConfig::class);
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = $this->getFile();

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $result = $sut->findInFiles($discoveredFiles);

        $this->assertNotContains('object', $result->getDiscoveredClasses());
    }

    /**
     * @covers ::findInFiles
     */
    public function testDefineConstant(): void
    {

        $contents = <<<'EOD'
<?php
/*******************************************************************************
 * FPDF                                                                         *
 *                                                                              *
 * Version: 1.83                                                                *
 * Date:    2021-04-18                                                          *
 * Author:  Olivier PLATHEY                                                     *
 *******************************************************************************
 */

define('FPDF_VERSION', '1.83');

define('ANOTHER_CONSTANT', '1.83');

class FPDF
{}
EOD;

        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $discoveredSymbols = new DiscoveredSymbols();

        $config = $this->createMock(StraussConfig::class);
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = $this->getFile();

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $result = $sut->findInFiles($discoveredFiles);

        $constants = $result->getDiscoveredConstants()->toArray();

        $this->assertArrayHasKey('FPDF_VERSION', $constants);
        $this->assertArrayHasKey('ANOTHER_CONSTANT', $constants);
    }

    /**
     * @covers ::findInFiles
     */
    public function test_commented_namespace_is_invalid(): void
    {

        $contents = <<<'EOD'
<?php

// Global. - namespace WPGraphQL;

use WPGraphQL\Utils\Preview;

/**
 * Class WPGraphQL
 *
 * This is the one true WPGraphQL class
 *
 * @package WPGraphQL
 */
final class WPGraphQL {

}
EOD;

        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $discoveredSymbols = new DiscoveredSymbols();

        $config = $this->createMock(StraussConfig::class);
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = $this->getFile();

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $result = $sut->findInFiles($discoveredFiles);

        $this->assertArrayNotHasKey('WPGraphQL', $result->getDiscoveredNamespaces()->toArray());
        $this->assertArrayHasKey('WPGraphQL', $result->getDiscoveredClasses()->toArray());
    }

    /**
     * @covers ::findInFiles
     */
    public function testDiscoversGlobalFunctions(): void
    {

        $contents = <<<'EOD'
<?php

function topFunction() {
	return 'This should be recorded';
}

class MyClass {
    function aMethod() {
        // This should not be recorded
	}
}

function lowerFunction() {
	return 'This should be recorded';
}
EOD;


        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $discoveredSymbols = new DiscoveredSymbols();

        $config = $this->createMock(FileSymbolScannerConfigInterface::class);
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = $this->getFile();

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $result = $sut->findInFiles($discoveredFiles);

        $this->assertArrayHasKey('topFunction', $result->getDiscoveredFunctions()->toArray());
        $this->assertArrayNotHasKey('aMethod', $result->getDiscoveredFunctions()->toArray());
        $this->assertArrayHasKey('lowerFunction', $result->getDiscoveredFunctions()->toArray());
    }

    /**
     * @covers ::findInFiles
     * @covers ::find
     */
    public function testDiscoversGlobalFunctionInFunctionExists(): void
    {

        $contents = <<<'EOD'
<?php
if (! function_exists('collect')) {
    /**
     * Create a collection from the given value.
     *
     * @param  mixed  $value
     * @return \Custom\Prefix\Illuminate\Support\Collection
     */
    function collect($value = null)
    {
        return new Collection($value);
    }
}
EOD;


        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $discoveredSymbols = new DiscoveredSymbols();

        $config = $this->createMock(FileSymbolScannerConfigInterface::class);
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = $this->getFile();

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $result = $sut->findInFiles($discoveredFiles);

        $this->assertArrayHasKey('collect', $result->getDiscoveredFunctions()->toArray());
    }

    /**
     * @covers ::findInFiles
     */
    public function testDoesNotIncludeBuiltInPhpFunctions(): void
    {

        $contents = <<<'EOD'
<?php
// Polyfill
function mb_convert_case() {
	return 'This should not be recorded';
}
// Polyfill
function str_starts_with() {
	return 'This should not be recorded';
}

function lowerFunction() {
	return 'This should be recorded';
}
EOD;


        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $discoveredSymbols = new DiscoveredSymbols();

        $config = $this->createMock(FileSymbolScannerConfigInterface::class);
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = $this->getFile();

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $result = $sut->findInFiles($discoveredFiles);

        $this->assertArrayNotHasKey('str_starts_with', $result->getDiscoveredFunctions()->toArray());
        $this->assertArrayNotHasKey('mb_convert_case', $result->getDiscoveredFunctions()->toArray());
        $this->assertArrayHasKey('lowerFunction', $result->getDiscoveredFunctions()->toArray());
    }

    /**
     * Twig has global functions in the second namespace in its file.
     *
     * We were accidentally matching _everything_ using `[\s\S]*` instead of blank space with `[\s\n]*`.
     *
     * @covers ::findInFiles()
     *
     * @see https://github.com/twigphp/Twig/blob/v3.8.0/src/Extension/CoreExtension.php
     */
    public function test_finds_functions_in_second_namespace(): void
    {

        $contents = <<<'EOD'
<?php

namespace Twig\Extension {
	final class CoreExtension extends AbstractExtension {
		// Whatever.
	}
}

namespace {
	function twig_cycle($values, $position)
	{
		// Also whatever.
	}
}
EOD;


        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $discoveredSymbols = new DiscoveredSymbols();

        $config = $this->createMock(FileSymbolScannerConfigInterface::class);
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = $this->getFile();

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $result = $sut->findInFiles($discoveredFiles);

        $this->assertArrayHasKey('twig_cycle', $result->getDiscoveredFunctions()->toArray());
    }

    /**
     * Template files with placeholder tokens (e.g. `%g_namespace%`) are not valid PHP.
     * Strauss should skip them gracefully rather than throwing a fatal error.
     *
     * @covers ::findInFiles
     */
    public function testTemplateFileWithPlaceholdersIsSkippedGracefully(): void
    {
        $contents = <<<'EOD'
<?php

namespace %g_namespace%\AdminMenus;

use %g_use_libs%\AdminMenus\AbstractAdminMenu;

class AdminMenuExample extends AbstractAdminMenu
{
}
EOD;

        $filesystemReaderMock = Mockery::mock(FileSystem::class);
        $filesystemReaderMock->expects('read')->once()->andReturn($contents);
        $filesystemReaderMock->expects('getRelativePath')->once()->andReturnArg(1);

        $discoveredSymbols = new DiscoveredSymbols();

        $config = $this->createMock(FileSymbolScannerConfigInterface::class);
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystemReaderMock);

        $file = $this->getFile();

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $result = $sut->findInFiles($discoveredFiles);

        $this->assertEmpty($result->getDiscoveredClasses()->toArray());
    }
}
