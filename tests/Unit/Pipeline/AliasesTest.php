<?php

namespace BrianHenryIE\Strauss\Tests\Unit\Pipeline;

use BrianHenryIE\Strauss\Config\AliasesConfigInterface;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Pipeline\Aliases;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Mockery;
use Psr\Log\NullLogger;

/**
 * @coversNothing
 * @see Aliases
 */
class AliasesTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('Foo\\Bar\\Baz')) {
            $includeFilePath = sys_get_temp_dir() . '/foo_bar_baz.php';
            $includeFile = '<?php namespace Foo\\Bar; class Baz {}';
            file_put_contents($includeFilePath, $includeFile);
            include $includeFilePath;
            file_exists($includeFilePath) && unlink($includeFilePath);
        }
    }

    /**
     * Until now, the output was a list of `class_alias()` etc. calls, but where the class they extended was not yet
     * loaded caused problems. I.e. don't add a class alias unless there's an autoloader for anything it might extend.
     */
    public function test_spl_autoloader(): void
    {

        $config = Mockery::mock(AliasesConfigInterface::class);
        $config->expects('isDryRun')->twice()->andReturnTrue();
        $config->expects('getVendorDirectory')->twice()->andReturn('vendor/');
        $config->expects('getTargetDirectory')->once()->andReturn('vendor-prefixed/');
        $config->expects('getNamespacePrefix')->times(2)->andReturn('Baz\\');

        $fileSystem = $this->getFileSystem();

        $sut = new Aliases(
            $config,
            $fileSystem,
            new NullLogger()
        );

        $symbols = new DiscoveredSymbols();
        $file = Mockery::mock(FileWithDependency::class);
        $file->expects('getSourcePath')->times(2)->andReturn('vendor/foo/bar/baz.php');
        $file->expects('addDiscoveredSymbol')->once();
        $file->expects('getAbsoluteTargetPath')->once()->andReturn('vendor-prefixed/foo/bar/baz.php');

        $fileSystem->write('vendor/foo/bar/baz.php', '<?php namespace Foo\\Bar; class Baz {}');
        $fileSystem->write('vendor-prefixed/foo/bar/baz.php', '<?php namespace Baz\\Foo\\Bar; class Baz {}');

        $namespaceSymbol = new NamespaceSymbol('Foo\\Bar', $file);
        $namespaceSymbol->setReplacement('Baz\\Foo\\Bar');
        $symbols->add($namespaceSymbol);

        $sut->writeAliasesFileForSymbols($symbols);

        $result = $fileSystem->read('vendor/composer/autoload_aliases.php');

        $expected = <<<'EOD'
'Foo\\Bar\\Baz' => 
	array (
		'type' => 'class',
		'classname' => 'Baz',
		'isAbstract' => 'false',
		'namespace' => 'Foo\\Bar',
		'extends' => 'Baz\\Foo\\Bar\\Baz',
		'implements' => 
			array (
			),
		'traits' => 
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
        $config->expects('isDryRun')->twice()->andReturnTrue();
        $config->expects('getVendorDirectory')->twice()->andReturn('vendor/');
        $config->expects('getTargetDirectory')->once()->andReturn('vendor-prefixed/');
        $config->expects('getNamespacePrefix')->times(2)->andReturn('Baz\\');

        $fileSystem = $this->getFileSystem();

        $sut = new Aliases(
            $config,
            $fileSystem,
            new NullLogger()
        );

        $symbols = new DiscoveredSymbols();
        $file = Mockery::mock(FileWithDependency::class);
        $file->expects('getSourcePath')->once()->andReturn('vendor/foo/bar/baz.php');
        $file->expects('addDiscoveredSymbol')->once();

        $fileSystem->write('vendor/foo/bar/baz.php', '<?php namespace Foo\\Bar; class Baz {}');
        $fileSystem->write('vendor-prefixed/foo/bar/baz.php', '<?php namespace Baz\\Foo\\Bar; class Baz {}');

        $functionSymbol = new FunctionSymbol('foo', $file);
        $functionSymbol->setReplacement('bar_foo');
        $symbols->add($functionSymbol);

        $sut->writeAliasesFileForSymbols($symbols);

        $result = $fileSystem->read('vendor/composer/autoload_aliases.php');

        $expected = <<<'EOD'
if(!function_exists('foo')){
    function foo(...$args) { return bar_foo(func_get_args()); }
}
EOD;
        $this->assertStringContainsStringRemoveBlankLinesLeadingWhitespace($expected, $result);
    }

    public function test_namespaced_interfaces(): void
    {

        $config = Mockery::mock(AliasesConfigInterface::class);
        $config->expects('isDryRun')->twice()->andReturnTrue();
        $config->expects('getVendorDirectory')->twice()->andReturn('vendor/');
        $config->expects('getTargetDirectory')->once()->andReturn('vendor-prefixed/');
        $config->expects('getNamespacePrefix')->times(2)->andReturn('Baz\\');

        $fileSystem = $this->getFileSystem();

        $sut = new Aliases(
            $config,
            $fileSystem,
            new NullLogger()
        );

        $symbols = new DiscoveredSymbols();
        $file = Mockery::mock(FileWithDependency::class);
        $file->expects('getSourcePath')->times(2)->andReturn('vendor/foo/bar/baz.php');
        $file->expects('addDiscoveredSymbol')->once();
        $file->expects('getAbsoluteTargetPath')->once()->andReturn('vendor-prefixed/foo/bar/baz.php');

        $fileSystem->write('vendor/foo/bar/baz.php', '<?php namespace Foo\\Bar; interface Baz {}');
        $fileSystem->write('vendor-prefixed/foo/bar/baz.php', '<?php namespace Baz\\Foo\\Bar; interface Baz {}');

        $namespaceSymbol = new NamespaceSymbol('Foo\\Bar', $file);
        $namespaceSymbol->setReplacement('Baz\\Foo\\Bar');
        $symbols->add($namespaceSymbol);

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
}
