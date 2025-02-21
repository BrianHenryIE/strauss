<?php

namespace BrianHenryIE\Strauss\Tests\Unit\Pipeline;

use BrianHenryIE\Strauss\Config\AliasesConfigInterace;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Pipeline\Aliases;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Psr\Log\NullLogger;

class AliasesTest extends TestCase
{

    /**
     * Until now, the output was a list of `class_alias()` etc. calls, but where the class they extended was not yet
     * loaded caused problems. I.e. don't add a class alias unless there's an autoloader for anything it might extend.
     */
    public function test_spl_autoloader(): void
    {

        $config = \Mockery::mock(AliasesConfigInterace::class);
        $config->expects('isDryRun')->andReturnTrue();
        $config->expects('getVendorDirectory')->andReturn('vendor/');
        $config->expects('getTargetDirectory')->andReturn('vendor-prefixed/');

        $fileSystem = $this->getFileSystem();

        $sut = new Aliases(
            $config,
            '/',
            $fileSystem,
            new NullLogger()
        );

        $symbols = new DiscoveredSymbols();
        $file = \Mockery::mock(FileWithDependency::class);
        $file->expects('getSourcePath')->once()->andReturn('vendor/foo/bar/baz.php');
        $file->expects('addDiscoveredSymbol')->once();

        $fileSystem->write('vendor/foo/bar/baz.php', '<?php namespace Foo\\Bar; class Baz {}');
        $fileSystem->write('vendor-prefixed/foo/bar/baz.php', '<?php namespace Baz\\Foo\\Bar; class Baz {}');

        $namespaceSymbol = new NamespaceSymbol('Foo\\Bar', $file);
        $namespaceSymbol->setReplacement('Baz\\Foo\\Bar');
        $symbols->add($namespaceSymbol);

        $sut->writeAliasesFileForSymbols($symbols);

        $result = $fileSystem->read('vendor/composer/autoload_aliases.php');

        $expected = <<<'EOD'
<?php

// autoload_aliases.php @generated by Strauss

function autoloadAliases( $classname ): void {
  switch( $classname ) {
    case 'Foo\\Bar\\Baz':
      class_alias(\Baz\Foo\Bar\Baz::class, \Foo\Bar\Baz::class);
      break;
    default:
      // Not in this autoloader.
      break;
  }
}

spl_autoload_register( 'autoloadAliases' );

EOD;
        $this->assertEqualsRemoveBlankLinesLeadingWhitespace($expected, $result);
    }

    /**
     * functions don't get autoloaded so still need to be just defined in the file.
     */
    public function test_functions(): void
    {

        $config = \Mockery::mock(AliasesConfigInterace::class);
        $config->expects('isDryRun')->andReturnTrue();
        $config->expects('getVendorDirectory')->andReturn('vendor/');
        $config->expects('getTargetDirectory')->andReturn('vendor-prefixed/');

        $fileSystem = $this->getFileSystem();

        $sut = new Aliases(
            $config,
            '/',
            $fileSystem,
            new NullLogger()
        );

        $symbols = new DiscoveredSymbols();
        $file = \Mockery::mock(FileWithDependency::class);
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
<?php

// autoload_aliases.php @generated by Strauss

function foo(...$args) { return bar_foo(func_get_args()); }

EOD;
        $this->assertEqualsRemoveBlankLinesLeadingWhitespace($expected, $result);
    }

    public function test_namespaced_interfaces(): void
    {

        $config = \Mockery::mock(AliasesConfigInterace::class);
        $config->expects('isDryRun')->andReturnTrue();
        $config->expects('getVendorDirectory')->andReturn('vendor/');
        $config->expects('getTargetDirectory')->andReturn('vendor-prefixed/');

        $fileSystem = $this->getFileSystem();

        $sut = new Aliases(
            $config,
            '/',
            $fileSystem,
            new NullLogger()
        );

        $symbols = new DiscoveredSymbols();
        $file = \Mockery::mock(FileWithDependency::class);
        $file->expects('getSourcePath')->once()->andReturn('vendor/foo/bar/baz.php');
        $file->expects('addDiscoveredSymbol')->once();

        $fileSystem->write('vendor/foo/bar/baz.php', '<?php namespace Foo\\Bar; interface Baz {}');
        $fileSystem->write('vendor-prefixed/foo/bar/baz.php', '<?php namespace Baz\\Foo\\Bar; interface Baz {}');

        $namespaceSymbol = new NamespaceSymbol('Foo\\Bar', $file);
        $namespaceSymbol->setReplacement('Baz\\Foo\\Bar');
        $symbols->add($namespaceSymbol);

        $sut->writeAliasesFileForSymbols($symbols);

        $result = $fileSystem->read('vendor/composer/autoload_aliases.php');

        $expected = <<<'EOD'
<?php

// autoload_aliases.php @generated by Strauss

function autoloadAliases( $classname ): void {
  switch( $classname ) {
    case 'Foo\\Bar\\Baz':
      $includeFile = '<?php namespace Foo\Bar; interface Baz extends \Baz\Foo\Bar\Baz {};';
      include "data://text/plain;base64," . base64_encode($includeFile);
      break;
    default:
      // Not in this autoloader.
      break;
  }
}

spl_autoload_register( 'autoloadAliases' );

EOD;
        $this->assertEqualsRemoveBlankLinesLeadingWhitespace($expected, $result);
    }
}
