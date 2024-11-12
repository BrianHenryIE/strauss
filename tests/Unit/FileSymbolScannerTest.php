<?php

namespace BrianHenryIE\Strauss\Tests\Unit;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Pipeline\FileSymbolScanner;
use BrianHenryIE\Strauss\TestCase;

class FileSymbolScannerTest extends TestCase
{

    // PREG_BACKTRACK_LIMIT_ERROR

    // Single implied global namespace.
    // Single named namespace.
    // Single explicit global namespace.
    // Multiple namespaces.



    public function testSingleNamespace()
    {

        $validPhp = <<<'EOD'
<?php
namespace MyNamespace;

class MyClass {
}
EOD;

        $file = \Mockery::mock(File::class);
        $file->expects('addDiscoveredSymbol')->once();

        $config = $this->createMock(StraussConfig::class);
        $config->method('getNamespacePrefix')->willReturn('Prefix');
        $sut = new FileSymbolScanner($config);

        $file = \Mockery::mock(File::class);
        $file->shouldReceive('isPhpFile')->andReturnTrue();
        $file->shouldReceive('getContents')->andReturn($validPhp);

        $file->shouldReceive('getTargetRelativePath');
        $file->shouldReceive('getDependency');
        $file->shouldReceive('addDiscoveredSymbol');

        $discoveredFiles = \Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $sut->findInFiles($discoveredFiles);

        self::assertArrayHasKey('MyNamespace', $discoveredSymbols->getDiscoveredNamespaces());
//        self::assertContains('Prefix\MyNamespace', $sut->getDiscoveredNamespaces());

        self::assertNotContains('MyClass', $discoveredSymbols->getDiscoveredClasses());
    }

    public function testGlobalNamespace()
    {

        $validPhp = <<<'EOD'
<?php
namespace {
    class MyClass {
    }
}
EOD;
        $file = \Mockery::mock(File::class);
        $file->expects('addDiscoveredSymbol')->once();

        $config = $this->createMock(StraussConfig::class);
        $sut = new FileSymbolScanner($config);

        $file = \Mockery::mock(File::class);
        $file->shouldReceive('isPhpFile')->andReturnTrue();
        $file->shouldReceive('getContents')->andReturn($validPhp);

        $file->shouldReceive('getTargetRelativePath');
        $file->shouldReceive('getDependency');
        $file->shouldReceive('addDiscoveredSymbol');

        $discoveredFiles = \Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $sut->findInFiles($discoveredFiles);
        self::assertContains('MyClass', $discoveredSymbols->getDiscoveredClasses());
    }

    /**
     *
     */
    public function testMultipleNamespace()
    {

        $validPhp = <<<'EOD'
<?php
namespace MyNamespace {
}
namespace {
    class MyClass {
    }
}
EOD;
        $file = \Mockery::mock(File::class);
        $file->expects('addDiscoveredSymbol')->once();

        $config = $this->createMock(StraussConfig::class);
        $sut = new FileSymbolScanner($config);

        $file = \Mockery::mock(File::class);
        $file->shouldReceive('isPhpFile')->andReturnTrue();
        $file->shouldReceive('getContents')->andReturn($validPhp);

        $file->shouldReceive('getTargetRelativePath');
        $file->shouldReceive('getDependency');
        $file->shouldReceive('addDiscoveredSymbol');

        $discoveredFiles = \Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $sut->findInFiles($discoveredFiles);

        self::assertArrayHasKey('MyNamespace', $discoveredSymbols->getDiscoveredNamespaces());

        self::assertContains('MyClass', $discoveredSymbols->getDiscoveredClasses());
    }


    /**
     *
     */
    public function testMultipleNamespaceGlobalFirst()
    {

        $validPhp = <<<'EOD'
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
        $file = \Mockery::mock(File::class);
        $file->expects('addDiscoveredSymbol')->once();

        $config = $this->createMock(StraussConfig::class);
        $sut = new FileSymbolScanner($config);


        $file = \Mockery::mock(File::class);
        $file->shouldReceive('isPhpFile')->andReturnTrue();
        $file->shouldReceive('getContents')->andReturn($validPhp);

        $file->shouldReceive('getTargetRelativePath');
        $file->shouldReceive('getDependency');
        $file->shouldReceive('addDiscoveredSymbol');

        $discoveredFiles = \Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $sut->findInFiles($discoveredFiles);

        self::assertArrayHasKey('MyNamespace', $discoveredSymbols->getDiscoveredNamespaces());

        self::assertContains('MyClass', $discoveredSymbols->getDiscoveredClasses());
        self::assertNotContains('MyOtherClass', $discoveredSymbols->getDiscoveredClasses());
    }

    public function testItDoesNotFindNamespaceInComment(): void
    {

        $validPhp = <<<'EOD'
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
        $file = \Mockery::mock(File::class);
        $file->expects('addDiscoveredSymbol')->once();

        $config = $this->createMock(StraussConfig::class);
        $sut = new FileSymbolScanner($config);

        try {
            $file = \Mockery::mock(File::class);
            $file->shouldReceive('isPhpFile')->andReturnTrue();
            $file->shouldReceive('getContents')->andReturn($validPhp);

            $file->shouldReceive('getTargetRelativePath');
            $file->shouldReceive('getDependency');
            $file->shouldReceive('addDiscoveredSymbol');

            $discoveredFiles = \Mockery::mock(DiscoveredFiles::class);
            $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

            $discoveredSymbols = $sut->findInFiles($discoveredFiles);
        } catch (\PHPUnit\Framework\Error\Warning $e) {
            self::fail('Should not throw an exception');
        }

        self::assertEmpty($discoveredSymbols->getDiscoveredNamespaces());
    }

    /**
     *
     */
    public function testMultipleClasses()
    {

        $validPhp = <<<'EOD'
<?php
class MyClass {
}
class MyOtherClass {

}
EOD;
        $file = \Mockery::mock(File::class);
        $file->expects('addDiscoveredSymbol')->once();

        $config = $this->createMock(StraussConfig::class);
        $sut = new FileSymbolScanner($config);

        $file = \Mockery::mock(File::class);
        $file->shouldReceive('isPhpFile')->andReturnTrue();
        $file->shouldReceive('getContents')->andReturn($validPhp);

        $file->shouldReceive('getTargetRelativePath');
        $file->shouldReceive('getDependency');
        $file->shouldReceive('addDiscoveredSymbol');

        $discoveredFiles = \Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $sut->findInFiles($discoveredFiles);

        self::assertContains('MyClass', $discoveredSymbols->getDiscoveredClasses());
        self::assertContains('MyOtherClass', $discoveredSymbols->getDiscoveredClasses());
    }

    /**
     *
     * @author BrianHenryIE
     */
    public function test_it_does_not_treat_comments_as_classes()
    {
        $contents = "
    	// A class as good as any.
    	class Whatever {
    	
    	}
    	";

        $file = \Mockery::mock(File::class);
        $file->expects('addDiscoveredSymbol')->once();

        $config = $this->createMock(StraussConfig::class);
        $fileScanner = new FileSymbolScanner($config);

        $file = \Mockery::mock(File::class);
        $file->shouldReceive('isPhpFile')->andReturnTrue();
        $file->shouldReceive('getContents')->andReturn($contents);

        $file->shouldReceive('getTargetRelativePath');
        $file->shouldReceive('getDependency');
        $file->shouldReceive('addDiscoveredSymbol');

        $discoveredFiles = \Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $fileScanner->findInFiles($discoveredFiles);

        self::assertNotContains('as', $discoveredSymbols->getDiscoveredClasses());
        self::assertContains('Whatever', $discoveredSymbols->getDiscoveredClasses());
    }

    /**
     *
     * @author BrianHenryIE
     */
    public function test_it_does_not_treat_multiline_comments_as_classes()
    {
        $contents = "
    	 /**
    	  * A class as good as any; class as.
    	  */
    	class Whatever {
    	}
    	";

        $file = \Mockery::mock(File::class);
        $file->expects('addDiscoveredSymbol')->once();

        $config = $this->createMock(StraussConfig::class);
        $fileScanner = new FileSymbolScanner($config);

        $file = \Mockery::mock(File::class);
        $file->shouldReceive('isPhpFile')->andReturnTrue();
        $file->shouldReceive('getContents')->andReturn($contents);

        $file->shouldReceive('getTargetRelativePath');
        $file->shouldReceive('getDependency');
        $file->shouldReceive('addDiscoveredSymbol');

        $discoveredFiles = \Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $fileScanner->findInFiles($discoveredFiles);

        self::assertNotContains('as', $discoveredSymbols->getDiscoveredClasses());
        self::assertContains('Whatever', $discoveredSymbols->getDiscoveredClasses());
    }

    /**
     * This worked without adding the expected regex:
     *
     * // \s*\\/?\\*{2,}[^\n]* |                        # Skip multiline comment bodies
     *
     * @author BrianHenryIE
     */
    public function test_it_does_not_treat_multiline_comments_opening_line_as_classes()
    {
        $contents = "
    	 /** A class as good as any; class as.
    	  *
    	  */
    	class Whatever {
    	}
    	";

        $file = \Mockery::mock(File::class);
        $file->expects('addDiscoveredSymbol')->once();

        $config = $this->createMock(StraussConfig::class);
        $fileScanner = new FileSymbolScanner($config);

        $file = \Mockery::mock(File::class);
        $file->shouldReceive('isPhpFile')->andReturnTrue();
        $file->shouldReceive('getContents')->andReturn($contents);

        $file->shouldReceive('getTargetRelativePath');
        $file->shouldReceive('getDependency');
        $file->shouldReceive('addDiscoveredSymbol');

        $discoveredFiles = \Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $fileScanner->findInFiles($discoveredFiles);

        self::assertNotContains('as', $discoveredSymbols->getDiscoveredClasses());
        self::assertContains('Whatever', $discoveredSymbols->getDiscoveredClasses());
    }


    /**
     *
     * @author BrianHenryIE
     */
    public function test_it_does_not_treat_multiline_comments_on_one_line_as_classes()
    {
        $contents = "
    	 /** A class as good as any; class as. */ class Whatever_Trevor {
    	}
    	";

        $file = \Mockery::mock(File::class);
        $file->expects('addDiscoveredSymbol')->once();

        $config = $this->createMock(StraussConfig::class);
        $fileScanner = new FileSymbolScanner($config);

        $file = \Mockery::mock(File::class);
        $file->shouldReceive('isPhpFile')->andReturnTrue();
        $file->shouldReceive('getContents')->andReturn($contents);

        $file->shouldReceive('getTargetRelativePath');
        $file->shouldReceive('getDependency');
        $file->shouldReceive('addDiscoveredSymbol');

        $discoveredFiles = \Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $fileScanner->findInFiles($discoveredFiles);

        self::assertNotContains('as', $discoveredSymbols->getDiscoveredClasses());
        self::assertContains('Whatever_Trevor', $discoveredSymbols->getDiscoveredClasses());
    }

    /**
     * If someone were to put a semicolon in the comment it would mess with the previous fix.
     *
     * @author BrianHenryIE
     *
     * @test
     */
    public function test_it_does_not_treat_comments_with_semicolons_as_classes()
    {
        $contents = "
    	// A class as good as any; class as versatile as any.
    	class Whatever_Ever {
    	
    	}
    	";
        $file = \Mockery::mock(File::class);
        $file->expects('addDiscoveredSymbol')->once();

        $config = $this->createMock(StraussConfig::class);
        $fileScanner = new FileSymbolScanner($config);

        $file = \Mockery::mock(File::class);
        $file->shouldReceive('isPhpFile')->andReturnTrue();
        $file->shouldReceive('getContents')->andReturn($contents);

        $file->shouldReceive('getTargetRelativePath');
        $file->shouldReceive('getDependency');
        $file->shouldReceive('addDiscoveredSymbol');

        $discoveredFiles = \Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $fileScanner->findInFiles($discoveredFiles);

        self::assertNotContains('as', $discoveredSymbols->getDiscoveredClasses());
        self::assertContains('Whatever_Ever', $discoveredSymbols->getDiscoveredClasses());
    }

    /**
     * @author BrianHenryIE
     */
    public function test_it_parses_classes_after_semicolon()
    {

        $contents = "
	    myvar = 123; class Pear { };
	    ";
        $file = \Mockery::mock(File::class);
        $file->expects('addDiscoveredSymbol')->once();

        $config = $this->createMock(StraussConfig::class);
        $fileScanner = new FileSymbolScanner($config);

        $file = \Mockery::mock(File::class);
        $file->shouldReceive('isPhpFile')->andReturnTrue();
        $file->shouldReceive('getContents')->andReturn($contents);

        $file->shouldReceive('getTargetRelativePath');
        $file->shouldReceive('getDependency');
        $file->shouldReceive('addDiscoveredSymbol');

        $discoveredFiles = \Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $fileScanner->findInFiles($discoveredFiles);

        self::assertContains('Pear', $discoveredSymbols->getDiscoveredClasses());
    }


    /**
     * @author BrianHenryIE
     */
    public function test_it_parses_classes_followed_by_comment()
    {

        $contents = <<<'EOD'
	class WP_Dependency_Installer {
		/**
		 *
		 */
EOD;
        $file = \Mockery::mock(File::class);
        $file->expects('addDiscoveredSymbol')->once();

        $config = $this->createMock(StraussConfig::class);
        $fileScanner = new FileSymbolScanner($config);

        $file = \Mockery::mock(File::class);
        $file->shouldReceive('isPhpFile')->andReturnTrue();
        $file->shouldReceive('getContents')->andReturn($contents);

        $file->shouldReceive('getTargetRelativePath');
        $file->shouldReceive('getDependency');
        $file->shouldReceive('addDiscoveredSymbol');

        $discoveredFiles = \Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $fileScanner->findInFiles($discoveredFiles);

        self::assertContains('WP_Dependency_Installer', $discoveredSymbols->getDiscoveredClasses());
    }


    /**
     * It's possible to have multiple namespaces inside one file.
     *
     * To have two classes in one file, one in a namespace and the other not, the global namespace needs to be explicit.
     *
     * @author BrianHenryIE
     *
     * @test
     */
    public function it_does_not_replace_inside_named_namespace_but_does_inside_explicit_global_namespace_a(): void
    {

        $contents = "
		namespace My_Project {
			class A_Class { }
		}
		namespace {
			class B_Class { }
		}
		";

        $file = \Mockery::mock(File::class);
        $file->expects('addDiscoveredSymbol')->once();

        $config = $this->createMock(StraussConfig::class);
        $fileScanner = new FileSymbolScanner($config);

        $file = \Mockery::mock(File::class);
        $file->shouldReceive('isPhpFile')->andReturnTrue();
        $file->shouldReceive('getContents')->andReturn($contents);

        $file->shouldReceive('getTargetRelativePath');
        $file->shouldReceive('getDependency');
        $file->shouldReceive('addDiscoveredSymbol');

        $discoveredFiles = \Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $fileScanner->findInFiles($discoveredFiles);

        self::assertNotContains('A_Class', $discoveredSymbols->getDiscoveredClasses());
        self::assertContains('B_Class', $discoveredSymbols->getDiscoveredClasses());
    }

    public function testExcludePackagesFromPrefix()
    {

        $config = $this->createMock(StraussConfig::class);
        $config->method('getExcludePackagesFromPrefixing')->willReturn(
            array('brianhenryie/pdfhelpers')
        );

        $composerPackage = $this->createMock(ComposerPackage::class);
        $composerPackage->method('getPackageName')->willReturn('brianhenryie/pdfhelpers');

        $file = \Mockery::mock(File::class);
        $file->shouldReceive('isPhpFile')->andReturnTrue();
        $file->shouldReceive('getContents')->andReturn('');

        $files = \Mockery::mock(DiscoveredFiles::class)->makePartial();
        $files->shouldReceive('getFiles')->andReturn([$file]);

        $fileScanner = new FileSymbolScanner($config);
        $discoveredSymbols = $fileScanner->findInFiles($files);

        self::assertEmpty($discoveredSymbols->getDiscoveredNamespaces());
    }


    public function testExcludeFilePatternsFromPrefix()
    {
        $config = $this->createMock(StraussConfig::class);
        $config->method('getExcludeFilePatternsFromPrefixing')->willReturn(
            array('/to/')
        );

        $composerPackage = $this->createMock(ComposerPackage::class);
        $composerPackage->method('getPackageName')->willReturn('brianhenryie/pdfhelpers');

//        $file = new File($composerPackage, 'path/to/file', 'irrelevantPath');
        $file = \Mockery::mock(File::class);
        $file->shouldReceive('isPhpFile')->andReturnTrue();
        $file->shouldReceive('getContents')->andReturn('');

        $files = \Mockery::mock(DiscoveredFiles::class)->makePartial();
        $files->shouldReceive('getFiles')->andReturn([$file]);

        $fileScanner = new FileSymbolScanner($config);
        $discoveredSymbols = $fileScanner->findInFiles($files);

        self::assertEmpty($discoveredSymbols->getDiscoveredNamespaces());
    }

    /**
     * Test custom replacements
     */
    public function testNamespaceReplacementPatterns()
    {

        $contents = "
		namespace BrianHenryIE\PdfHelpers {
			class A_Class { }
		}
		";
        $file = \Mockery::mock(File::class);
        $file->expects('addDiscoveredSymbol')->once();

        $config = $this->createMock(StraussConfig::class);
        $config->method('getNamespacePrefix')->willReturn('BrianHenryIE\Prefix');
        $config->method('getNamespaceReplacementPatterns')->willReturn(
            array('/BrianHenryIE\\\\(PdfHelpers)/'=>'BrianHenryIE\\Prefix\\\\$1')
        );

        $fileScanner = new FileSymbolScanner($config);

        $file = \Mockery::mock(File::class);
        $file->shouldReceive('isPhpFile')->andReturnTrue();
        $file->shouldReceive('getContents')->andReturn($contents);

        $file->shouldReceive('getTargetRelativePath');
        $file->shouldReceive('getDependency');
        $file->shouldReceive('addDiscoveredSymbol');

        $discoveredFiles = \Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $fileScanner->findInFiles($discoveredFiles);

        self::assertArrayHasKey('BrianHenryIE\PdfHelpers', $discoveredSymbols->getDiscoveredNamespaces());
//        self::assertContains('BrianHenryIE\Prefix\PdfHelpers', $fileScanner->getDiscoveredNamespaces());
//        self::assertNotContains('BrianHenryIE\Prefix\BrianHenryIE\PdfHelpers', $fileScanner->getDiscoveredNamespaces());
    }

    /**
     * @see https://github.com/BrianHenryIE/strauss/issues/19
     */
    public function testPhraseClassObjectIsNotMistaken()
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
        $file = \Mockery::mock(File::class);
        $file->expects('addDiscoveredSymbol')->once();

        $config = $this->createMock(StraussConfig::class);
        $fileScanner = new FileSymbolScanner($config);

        $file = \Mockery::mock(File::class);
        $file->shouldReceive('isPhpFile')->andReturnTrue();
        $file->shouldReceive('getContents')->andReturn($contents);

        $file->shouldReceive('getTargetRelativePath');
        $file->shouldReceive('getDependency');
        $file->shouldReceive('addDiscoveredSymbol');

        $discoveredFiles = \Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $fileScanner->findInFiles($discoveredFiles);

        self::assertNotContains('object', $discoveredSymbols->getDiscoveredClasses());
    }

    public function testDefineConstant()
    {

        $contents = <<<'EOD'
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
{
EOD;
        $file = \Mockery::mock(File::class);
        $file->expects('addDiscoveredSymbol')->once();

        $config = $this->createMock(StraussConfig::class);
        $fileScanner = new FileSymbolScanner($config);

        $file = \Mockery::mock(File::class);
        $file->shouldReceive('isPhpFile')->andReturnTrue();
        $file->shouldReceive('getContents')->andReturn($contents);

        $file->shouldReceive('getTargetRelativePath');
        $file->shouldReceive('getDependency');
        $file->shouldReceive('addDiscoveredSymbol');

        $discoveredFiles = \Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $fileScanner->findInFiles($discoveredFiles);

        $constants = $discoveredSymbols->getDiscoveredConstants();

        self::assertContains('FPDF_VERSION', $constants);
        self::assertContains('ANOTHER_CONSTANT', $constants);
    }

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
        $file = \Mockery::mock(File::class);
        $file->expects('addDiscoveredSymbol')->once();

        $config = $this->createMock(StraussConfig::class);
        $fileScanner = new FileSymbolScanner($config);

        $file = \Mockery::mock(File::class);
        $file->shouldReceive('isPhpFile')->andReturnTrue();
        $file->shouldReceive('getContents')->andReturn($contents);

        $file->shouldReceive('getTargetRelativePath');
        $file->shouldReceive('getDependency');
        $file->shouldReceive('addDiscoveredSymbol');

        $discoveredFiles = \Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $discoveredSymbols = $fileScanner->findInFiles($discoveredFiles);

        self::assertArrayNotHasKey('WPGraphQL', $discoveredSymbols->getDiscoveredNamespaces());
        self::assertContains('WPGraphQL', $discoveredSymbols->getDiscoveredClasses());
    }
}
