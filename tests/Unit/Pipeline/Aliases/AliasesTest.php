<?php

namespace BrianHenryIE\Strauss\Pipeline\Aliases;

use BrianHenryIE\Strauss\Config\AliasesConfigInterface;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\InterfaceSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use JsonMapper\Tests\Implementation\Models\NamespaceAliasObject;
use Mockery;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\Aliases\Aliases
 */
class AliasesTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('Foo\\Bar\\Baz')) {
            $includeFilePath = 'project/foo_bar_baz.php';
            $includeFile = '<?php namespace Foo\\Bar; class Baz {}';
            $this->getFileSystem()->write($includeFilePath, $includeFile);
            include $this->getFileSystem()->makeAbsolute($includeFilePath);
        }
    }

    /**
     * Until now, the output was a list of `class_alias()` etc. calls, but where the class they extended was not yet
     * loaded caused problems. I.e. don't add a class alias unless there's an autoloader for anything it might extend.
     */
    public function test_class_in_aliases_array(): void
    {

        $config = Mockery::mock(AliasesConfigInterface::class);
        $config->expects('getAbsoluteVendorDirectory')->times(1)->andReturn('vendor');
        $config->expects('getNamespacePrefix')->times(1)->andReturn('Baz\\');

        $fileSystem = $this->getInMemoryFileSystem();

        $sut = new Aliases(
            $config,
            $fileSystem,
            $this->getLogger()
        );

        $symbols = new DiscoveredSymbols();
        $file = Mockery::mock(FileWithDependency::class);
        $file->expects('getSourcePath')->times(2)->andReturn('vendor/foo/bar/baz.php');
        $file->expects('addDiscoveredSymbol')->times(2);

        $fileSystem->write('vendor/foo/bar/baz.php', '<?php namespace Foo\\Bar; class Baz {}');
        $fileSystem->write('vendor-prefixed/foo/bar/baz.php', '<?php namespace Baz\\Foo\\Bar; class Baz {}');

        $namespaceSymbol = new NamespaceSymbol(
            'Foo\\Bar',
            $file
        );

        $classSymbol = new ClassSymbol('Foo\\Bar\\Baz', $file, false, $namespaceSymbol);
        $classSymbol->setReplacement('Baz\\Foo\\Bar\\Baz');
        $symbols->add($classSymbol);

        $sut->writeAliasesFileForSymbols($symbols);

        $result = $fileSystem->read('vendor/composer/autoload_aliases.php');

        $expected = <<<'EOD'
'Foo\\Bar\\Baz' =>
	array (
		'type' => 'class',
		'classname' => 'Baz',
		'isabstract' => false,
		'namespace' => 'Foo\\Bar',
		'extends' => 'Baz\\Foo\\Bar\\Baz',
		'implements' =>
			array (
			),
),
EOD;

        $this->assertStringContainsStringRemoveBlankLinesLeadingWhitespace($expected, $result);
    }

    /**
     * functions don't get autoloaded so still need to be just defined in the file.
     */
    public function test_functions(): void
    {

        $config = Mockery::mock(AliasesConfigInterface::class);
        $config->expects('getAbsoluteVendorDirectory')->atLeast()->once()->andReturn('vendor');
        $config->expects('getNamespacePrefix')->atLeast()->once()->andReturn('Baz\\');

        $fileSystem = $this->getInMemoryFileSystem();

        $sut = new Aliases(
            $config,
            $fileSystem,
            $this->getLogger()
        );

        $symbols = new DiscoveredSymbols();

        $file = Mockery::mock(FileWithDependency::class);
        $file->expects('getSourcePath')->atLeast()->once()->andReturn('vendor/foo/bar/baz.php');
        $file->expects('addDiscoveredSymbol')->atLeast()->once();

        $fileSystem->write('vendor/foo/bar/baz.php', '<?php namespace Foo\\Bar; class Baz {}');
        $fileSystem->write('vendor-prefixed/foo/bar/baz.php', '<?php namespace Baz\\Foo\\Bar; class Baz {}');

        $functionSymbol = new FunctionSymbol('foo', $file);
        $functionSymbol->setReplacement('bar_foo');
        $symbols->add($functionSymbol);

        $namespaceSymbol = new NamespaceSymbol('Foo\\Bar', $file);
        $symbols->add($namespaceSymbol);

        $sut->writeAliasesFileForSymbols($symbols);

        $result = $fileSystem->read('vendor/composer/autoload_aliases.php');

        $expected = <<<'EOD'
if(!function_exists('\\foo')){
    function foo(...$args) {
      return \bar_foo(...func_get_args());
    }
}
EOD;
        $this->assertStringContainsStringRemoveBlankLinesLeadingWhitespace($expected, $result);
    }

    public function test_namespaced_interfaces(): void
    {
        $config = Mockery::mock(AliasesConfigInterface::class);
        $config->expects('getAbsoluteVendorDirectory')->times(1)->andReturn('vendor');
        $config->expects('getNamespacePrefix')->times(1)->andReturn('Baz\\');

        $fileSystem = $this->getInMemoryFileSystem();

        $sut = new Aliases(
            $config,
            $fileSystem,
            $this->getLogger()
        );

        $symbols = new DiscoveredSymbols();
        $file = Mockery::mock(FileWithDependency::class);
        $file->expects('getSourcePath')->times(2)->andReturn('vendor/foo/bar/baz.php');
        $file->expects('addDiscoveredSymbol')->times(2);

        $fileSystem->write('vendor/foo/bar/baz.php', '<?php namespace Foo\\Bar; interface Baz {}');
        $fileSystem->write('vendor-prefixed/foo/bar/baz.php', '<?php namespace Baz\\Foo\\Bar; interface Baz {}');

        $namespaceSymbol = new NamespaceSymbol(
            'Foo\\Bar',
            $file
        );

        $interfaceSymbol = new InterfaceSymbol('Foo\\Bar\\Baz', $file, $namespaceSymbol);
        $interfaceSymbol->setReplacement('Baz\\Foo\\Bar\\Baz');
        $symbols->add($interfaceSymbol);

        $sut->writeAliasesFileForSymbols($symbols);

        $result = $fileSystem->read('vendor/composer/autoload_aliases.php');

        $expected = <<<'EOD'
'Foo\\Bar\\Baz' =>
	array (
		'type' => 'interface',
		'interfacename' => 'Baz',
		'namespace' => 'Foo\\Bar',
		'extends' =>
		array (
			0 => 'Baz\\Foo\\Bar\\Baz',
			),
),
EOD;

        $this->assertStringContainsStringRemoveBlankLinesLeadingWhitespace($expected, $result);
    }

    /**
     * @covers ::getFunctionAliasesString()
     */
    public function test_namespaced_functions(): void
    {

        $config = Mockery::mock(AliasesConfigInterface::class);
        $config->expects('getAbsoluteVendorDirectory')->times(1)->andReturn('vendor');
        $config->expects('getNamespacePrefix')->times(1)->andReturn('Baz\\');

        $fileSystem = $this->getInMemoryFileSystem();

        $sut = new Aliases(
            $config,
            $fileSystem,
            $this->getLogger()
        );

        $symbols = new DiscoveredSymbols();
        $file = Mockery::mock(FileWithDependency::class);
        $file->expects('getSourcePath')->atLeast()->once()->andReturn('vendor/foo/bar/baz.php');
        $file->expects('addDiscoveredSymbol')->atLeast()->once();

        $fileSystem->write('vendor/foo/bar/baz.php', '<?php namespace Bar; function baz {}');
        $fileSystem->write('vendor-prefixed/foo/bar/baz.php', '<?php namespace Foo\\Bar; function baz {}');

        $namespaceSymbol = new NamespaceSymbol(
            'Bar',
            $file
        );

        $functionSymbol = new FunctionSymbol('baz', $file, $namespaceSymbol);
        $symbols->add($functionSymbol);

        $functionSymbol = new FunctionSymbol('foobar', $file, $namespaceSymbol);
        $symbols->add($functionSymbol);

        $namespaceSymbol = new NamespaceSymbol('Bar', $file);
        $namespaceSymbol->setReplacement('Foo\\Bar');
        $symbols->add($namespaceSymbol);

        $sut->writeAliasesFileForSymbols($symbols);

        $result = $fileSystem->read('vendor/composer/autoload_aliases.php');

        $expected = <<<'EOD'
namespace Bar {
	if(!function_exists('\\Bar\\baz')){
		function baz(...$args) {
			return \Foo\Bar\baz(...func_get_args());
		}
	}
	if(!function_exists('\\Bar\\foobar')){
		function foobar(...$args) {
			return \Foo\Bar\foobar(...func_get_args());
		}
	}
}
EOD;
        $this->assertStringContainsStringRemoveBlankLinesLeadingWhitespace($expected, $result);
    }
}
