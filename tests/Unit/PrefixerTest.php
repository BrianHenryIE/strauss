<?php
/**
 * @author https://github.com/coenjacobs
 * @author https://github.com/BrianHenryIE
 * @author https://github.com/markjaquith
 * @author https://github.com/stephenharris
 */

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Config\PrefixerConfigInterface;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Pipeline\Prefixer;
use BrianHenryIE\Strauss\Tests\Issues\MozartIssue93Test;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\InterfaceSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use BrianHenryIE\Strauss\Types\TraitSymbol;
use Mockery;
use PhpParser\ParserFactory;
use Symfony\Component\Console\Application as BaseApplication;

/**
 * Class ReplacerTest
 * @package BrianHenryIE\Strauss
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\Prefixer
 */
class PrefixerTest extends TestCase
{

    /**
     * @return PrefixerConfigInterface|(PrefixerConfigInterface&Mockery\MockInterface&object&Mockery\LegacyMockInterface)|(Mockery\MockInterface&object&Mockery\LegacyMockInterface)
     */
    protected function getMockConfig()
    {
        $config = Mockery::mock(PrefixerConfigInterface::class);
        $config->shouldReceive('getClassmapPrefix')->andReturn('Prefixer_Test_');
        $config->shouldReceive('getNamespacePrefix')->andReturn('Prefixer\\Test\\');
        $config->shouldReceive('getConstantsPrefix')->andReturn('Prefixer_Test_');
        return $config;
    }

    protected function getAst(string $contents): array
    {

        $parseContent = $contents;
        if (stripos(ltrim($contents), '<?') !== 0) {
            $phpOpenerLen = strlen("<?php\n");
            $parseContent = "<?php\n" . $contents;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        $ast = $parser->parse($parseContent);

        return $ast;
    }

    public function testNamespaceReplacer(): void
    {

        $contents = <<<'EOD'
<?php

namespace Google;

use Google\Http\Batch;
use TypeError;

class Service
{
}
EOD;
        $config = $this->createMock(PrefixerConfigInterface::class);

        $originalNamespace = 'Google\\Http';
        $replacement = 'BrianHenryIE\\Strauss\\Google\\Http';

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol($originalNamespace, $file);
        $namespaceSymbol->setLocalReplacement($replacement);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);
        $file->setDoUpdate(true);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $expected = 'use BrianHenryIE\\Strauss\\Google\\Http\\Batch;';

        $this->assertStringNotContainsString($expected, $result);
    }

    public function testReplaceNamespaceForClass(): void
    {

        $contents = <<<'EOD'
<?php

namespace Google;

use Google\Http\Batch;
use TypeError;

class Service
{
}
EOD;
        $config = $this->createMock(PrefixerConfigInterface::class);

        $originalNamespace = 'Google\\Http';
        $replacement = 'BrianHenryIE\\Strauss\\Google\\Http';

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol($originalNamespace, $file);
        $namespaceSymbol->setLocalReplacement($replacement);

        $classSymbol = new ClassSymbol('Google\Http\Batch', $file, false, $namespaceSymbol);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);
        $discoveredSymbols->add($classSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);
        $file->setDoUpdate(true);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $expected = 'use BrianHenryIE\\Strauss\\Google\\Http\\Batch;';

        $this->assertStringContainsString($expected, $result);
    }


    public function testClassnameReplacer(): void
    {

        $contents = <<<'EOD'
<?php
/*******************************************************************************
* FPDF                                                                         *
*                                                                              *
* Version: 1.82                                                                *
* Date:    2019-12-07                                                          *
* Author:  Olivier PLATHEY                                                     *
*******************************************************************************/

define('FPDF_VERSION','1.82');

class FPDF
{
protected $page;               // current page number
protected $n;                  // current object number
protected $offsets;            // array of object offsets
protected $buffer;             // buffer holding in-memory PDF
}
EOD;

        $originalClassname = "FPDF";
        $classnamePrefix = "Prefixer_Test_";

        $expected = "class Prefixer_Test_FPDF";

        $config = $this->getMockConfig();

        $sut = new Prefixer($config, $this->getInMemoryFileSystem());

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $classSymbol = new ClassSymbol($originalClassname, $file);
        $classSymbol->setLocalReplacement($classnamePrefix . $originalClassname);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($classSymbol);

        $result = $sut->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertStringContainsString($expected, $result);
    }

    /**
     * PHP 7.4 typed parameters were being prefixed.
     */
    public function testTypeFunctionParameter(): void
    {
        $this->markTestIncomplete();
    }

    /**
     * @author CoenJacobs
     */
    public function test_it_replaces_class_declarations(): void
    {
        $contents = 'class Hello_World {}';

        $originalClassname = "Hello_World";
        $classnamePrefix = "Prefixer_Test_";

        $expected = "class Prefixer_Test_Hello_World {}";

        $config = $this->getMockConfig();

        $sut = new Prefixer($config, $this->getInMemoryFileSystem());

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $classSymbol = new ClassSymbol($originalClassname, $file);
        $classSymbol->setLocalReplacement($classnamePrefix . $originalClassname);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($classSymbol);

        $result = $sut->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertStringContainsString($expected, $result);
    }

    /**
     * @author CoenJacobs
     */
    public function test_it_replaces_abstract_class_declarations(): void
    {
        $contents = 'abstract class Hello_World {}';

        $originalClassname = "Hello_World";
        $classnamePrefix = "Prefixer_Test_";

        $expected = 'abstract class Prefixer_Test_Hello_World {}';

        $config = $this->getMockConfig();

        $sut = new Prefixer($config, $this->getInMemoryFileSystem());

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $classSymbol = new ClassSymbol($originalClassname, $file);
        $classSymbol->setLocalReplacement($classnamePrefix . $originalClassname);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($classSymbol);

        $result = $sut->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertStringContainsString($expected, $result);
    }

    /**
     * @author CoenJacobs
     */
    public function test_it_replaces_interface_class_declarations(): void
    {
        $contents = 'interface Hello_World {}';

        $originalName = "Hello_World";
        $globalPrefix = "Prefixer_Test_";

        $expected = 'interface Prefixer_Test_Hello_World {}';

        $config = $this->getMockConfig();

        $sut = new Prefixer($config, $this->getInMemoryFileSystem());

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $interfaceSymbol = new InterfaceSymbol($originalName, $file);
        $interfaceSymbol->setLocalReplacement($globalPrefix . $originalName);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($interfaceSymbol);

        $result = $sut->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertStringContainsString($expected, $result);
    }

    /**
     * @author CoenJacobs
     */
    public function test_it_replaces_class_declarations_that_extend_other_classes(): void
    {
        $contents = 'class Hello_World extends Bye_World {}';

        $originalClassname = "Hello_World";
        $classnamePrefix = "Prefixer_Test_";

        $expected = 'class Prefixer_Test_Hello_World extends Bye_World {}';

        $config = $this->getMockConfig();

        $sut = new Prefixer($config, $this->getInMemoryFileSystem());

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $classSymbol = new ClassSymbol($originalClassname, $file);
        $classSymbol->setLocalReplacement($classnamePrefix . $originalClassname);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($classSymbol);

        $result = $sut->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertStringContainsString($expected, $result);
    }

    /**
     * @author CoenJacobs
     */
    public function test_it_replaces_class_declarations_that_implement_interfaces(): void
    {
        $contents = 'class Hello_World implements Bye_World {}';

        $originalClassname = "Hello_World";
        $classnamePrefix = "Prefixer_Test_";

        $expected = 'class Prefixer_Test_Hello_World implements Bye_World {}';

        $config = $this->getMockConfig();

        $sut = new Prefixer($config, $this->getInMemoryFileSystem());

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $classSymbol = new ClassSymbol($originalClassname, $file);
        $classSymbol->setLocalReplacement($classnamePrefix . $originalClassname);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($classSymbol);

        $result = $sut->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertStringContainsString($expected, $result);
    }


    /**
     * @author BrianHenryIE
     */
    public function testItReplacesNamespacesInInterface(): void
    {
        $contents = 'class Hello_World implements \Strauss\Bye_World {}';

        $originalNamespace = 'Strauss';
        $replacement = 'Prefix\Strauss';

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol($originalNamespace, $file);
        $namespaceSymbol->setLocalReplacement($replacement);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN('class Hello_World implements \Prefix\Strauss\Bye_World {}', $result);
    }

    /**
     * @author CoenJacobs
     */
    public function test_it_stores_replaced_class_names(): void
    {
        $this->markTestIncomplete('TODO Delete/move');

        $contents = 'class Hello_World {';
        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());
        $replacer->setClassmapPrefix('Mozart_');
        $replacer->replace($contents);
        $this->assertArrayHasKey('Hello_World', $replacer->getReplacedClasses());
    }

    /**
     * @author https://github.com/stephenharris
     * @see https://github.com/coenjacobs/mozart/commit/fd7906943396c9a17110d1bfaf9d778f3b1f322a#diff-87828794e62b55ce8d7263e3ab1a918d1370e283ac750cd44e3ac61db5daee54
     */
    public function test_it_replaces_class_declarations_psr2(): void
    {
        $contents = "class Hello_World\n{}";

        $originalClassname = "Hello_World";
        $classnamePrefix = "Prefixer_Test_";

        $expected = "class Prefixer_Test_Hello_World\n{}";

        $config = $this->getMockConfig();

        $sut = new Prefixer($config, $this->getInMemoryFileSystem());

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $classSymbol = new ClassSymbol($originalClassname, $file);
        $classSymbol->setLocalReplacement($classnamePrefix . $originalClassname);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($classSymbol);

        $result = $sut->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertStringContainsString($expected, $result);
    }

    /**
     * @see https://github.com/coenjacobs/mozart/issues/81
     * @author BrianHenryIE
     *
     */
    public function test_it_replaces_class(): void
    {
        $contents = "class Hello_World {}";

        $originalClassname = "Hello_World";
        $classnamePrefix = "Prefixer_Test_";

        $expected = "class Prefixer_Test_Hello_World {}";

        $config = $this->getMockConfig();

        $sut = new Prefixer($config, $this->getInMemoryFileSystem());

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $classSymbol = new ClassSymbol($originalClassname, $file);
        $classSymbol->setLocalReplacement($classnamePrefix . $originalClassname);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($classSymbol);

        $result = $sut->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertStringContainsString($expected, $result);
    }


    /**
     * @see MozartIssue93Test
     * @see https://github.com/coenjacobs/mozart/issues/93
     *
     * @author BrianHenryIE
     *
     * @test
     */
    public function it_does_not_replace_inside_namespace_multiline(): void
    {
        self::markTestSkipped('No longer describes how the code behaves.');

        $contents = "
        namespace Mozart;
        class Hello_World
        ";

        $originalClassname = 'Hello_World';
        $classnamePrefix = 'Mozart_';

        $config = $this->createMock(PrefixerConfigInterface::class);
        $config->method("getClassmapPrefix")->willReturn($classnamePrefix);

        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

        $file = Mockery::mock(File::class);
        $file->shouldReceive('addDiscoveredSymbol');
        $namespaceSymbol = new NamespaceSymbol($originalClassname, $file);

        $result = $replacer->replaceInString([$originalClassname => $namespaceSymbol], [], [], $contents);

        $this->assertEqualsRN($contents, $result);
    }

    /**
     * @see MozartIssue93Test
     * @see https://github.com/coenjacobs/mozart/issues/93
     *
     * @author BrianHenryIE
     */
    public function test_it_does_not_replace_inside_namespace_singleline(): void
    {
        $contents = "namespace Mozart; class Hello_World";
        $expected = $contents;

        $originalClassname = 'Hello_World';
        $classnamePrefix = 'Prefixer_Test_';

        $config = $this->getMockConfig();

        $sut = new Prefixer($config, $this->getInMemoryFileSystem());

        $file = Mockery::mock(File::class);
        $file->shouldReceive('getSourcePath')->andReturn('prefixer_test.php');
        $file->shouldReceive('addDiscoveredSymbol');

        $classSymbol = new ClassSymbol($originalClassname, $file);
        $classSymbol->setLocalReplacement($classnamePrefix . $originalClassname);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($classSymbol);

        $result = $sut->replaceInString($discoveredSymbols, $contents);

        $this->assertEqualsRN($expected, $result);
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
    public function it_does_not_replace_inside_named_namespace_but_does_inside_explicit_global_namespace_b(): void
    {
        $contents = "
		namespace My_Project {
			class A_Class { }
		}
		namespace {
			class A_Class { }
		}
		";

        $expected = "
		namespace My_Project {
			class A_Class { }
		}
		namespace {
			class Prefixer_Test_A_Class { }
		}
		";

        $originalClassname = 'A_Class';
        $classnamePrefix = 'Prefixer_Test_';

        $config = $this->getMockConfig();

        $sut = new Prefixer($config, $this->getInMemoryFileSystem());

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $classSymbol = new ClassSymbol($originalClassname, $file);
        $classSymbol->setLocalReplacement($classnamePrefix . $originalClassname);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($classSymbol);

        $result = $sut->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertEqualsRN($expected, $result);
    }

    /** @test */
    public function it_replaces_namespace_declarations(): void
    {
        $contents = 'namespace Test\\Test;';

        $originalNamespace = "Test\\Test";
        $replacement = "My\\Mozart\\Prefix\\Test\\Test";

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol($originalNamespace, $file);
        $namespaceSymbol->setLocalReplacement($replacement);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN('namespace My\\Mozart\\Prefix\\Test\\Test;', $result);
    }


    /**
     * This test doesn't seem to match its name.
     */
    public function testRenamesNamespace(): void
    {
        $contents = "namespace Prefix\\Test\\Something;\n\nuse Test\\Test;";

        $originalNamespace1 = 'Prefix\\Test\\Something';
        $originalNamespace2 = 'Test';
        $replacement = 'My\\Mozart\\Rename\\Test';

        $expected = "namespace My\\Mozart\\Rename\\Test;\n\nuse My\\Mozart\\Rename\\Test\\Test;";

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol1 = new NamespaceSymbol($originalNamespace1, $file);
        $namespaceSymbol1->setLocalReplacement($replacement);

        $namespaceSymbol2 = new NamespaceSymbol($originalNamespace2, $file);
        $namespaceSymbol2->setLocalReplacement($replacement);

        $classSymbol = new ClassSymbol('Test\\Test', $file, false, $namespaceSymbol2);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol1);
        $discoveredSymbols->add($namespaceSymbol2);
        $discoveredSymbols->add($classSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }

    /**
     *
     */
    public function test_it_does_notreplaces_partial_namespace_declarations(): void
    {
        $contents = 'namespace Test\\Test\\Another;';

        $originalNamespace = 'Test\\Another';
        $replacement = 'My\\Mozart\\Prefix\\' . $originalNamespace;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol($originalNamespace, $file);
        $namespaceSymbol->setLocalReplacement($replacement);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN('namespace Test\\Test\\Another;', $result);
    }


    public function test_it_doesnt_prefix_already_prefixed_namespace(): void
    {
        $contents = 'namespace My\\Mozart\\Prefix\\Test\\Another;';

        $originalNamespace = "Test\\Another";
        $replacement = "My\\Mozart\\Prefix\\Test\\Another";

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol($originalNamespace, $file);
        $namespaceSymbol->setLocalReplacement($replacement);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN('namespace My\\Mozart\\Prefix\\Test\\Another;', $result);
    }

    /**
     * Trying to prefix standard namespace `Dragon`, e.g. `Dragon\Form` with `Dragon\Dependencies` results in
     * `Dragon\Dependencies\Dragon\Dependencies\Dragon\Form`.
     *
     * This was not the cause of the issue (i.e. this test, pretty much identical to the one above, passed immediately).
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/47
     */
    public function testDoesNotDoublePrefixAlreadyUpdatedNamespace(): void
    {
        $contents = 'namespace Dargon\\Dependencies\\Dragon\\Form;';

        $originalNamespace = "Dragon";
        $prefix = "Dargon\\Dependencies\\";
        $replacement = $prefix . $originalNamespace;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol($originalNamespace, $file);
        $namespaceSymbol->setLocalReplacement($replacement);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertNotEquals('namespace Dargon\\Dependencies\\Dargon\\Dependencies\\Dragon\\Form;', $result);
        $this->assertEqualsRN('namespace Dargon\\Dependencies\\Dragon\\Form;', $result);
    }

    /**
     * @author markjaquith
     */
    public function test_it_doesnt_double_replace_namespaces_that_also_exist_inside_another_namespace(): void
    {

        // This is a tricky situation. We are referencing Chicken\Egg,
        // but Egg *also* exists as a separate top level class.
        $contents = 'use Chicken\\Egg;';
        $expected = 'use My\\Mozart\\Prefix\\Chicken\\Egg;';

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $namespaceSymbol = new NamespaceSymbol('Chicken', $file);
        $namespaceSymbol->setLocalReplacement('My\\Mozart\\Prefix\\Chicken');
        $discoveredSymbols->add($namespaceSymbol);

        $classSymbol = new ClassSymbol('Chicken\Egg', $file, false, $namespaceSymbol);
        $discoveredSymbols->add($classSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }

    /**
     * @see https://github.com/coenjacobs/mozart/issues/75
     *
     * @test
     */
    public function it_replaces_namespace_use_as_declarations(): void
    {
        $originalNamespace = 'Symfony\\Polyfill\\';
        $replacement = "MBViews\\Dependencies\\Symfony\\Polyfill\\";

        $contents = "use Symfony\Polyfill\Mbstring as p;";

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $namespaceSymbol = new NamespaceSymbol($originalNamespace, $file);
        $namespaceSymbol->setLocalReplacement($replacement);
        $discoveredSymbols->add($namespaceSymbol);

        $classSymbol = new ClassSymbol('Symfony\Polyfill\Mbstring', $file, false, $namespaceSymbol);
        $discoveredSymbols->add($classSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $expected = "use MBViews\\Dependencies\\Symfony\\Polyfill\\Mbstring as p;";

        $this->assertEqualsRN($expected, $result);
    }

    /**
     * @author BrianHenryIE
     */
    public function test_it_doesnt_prefix_function_types_that_happen_to_match_the_namespace(): void
    {
        $originalNamespace = 'Mpdf';
        $replacement = "Mozart\\Mpdf";
        $contents = 'public function getServices( Mpdf $mpdf, LoggerInterface $logger, $config ) {}';

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol($originalNamespace, $file);
        $namespaceSymbol->setLocalReplacement($replacement);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $expected = 'public function getServices( Mpdf $mpdf, LoggerInterface $logger, $config ) {}';

        $this->assertEqualsRN($expected, $result);
    }

    public function testLeadingSlashInString(): void
    {
        $contents = '$mentionedClass = "\\Strauss\\Test\\Classname";';

        $originalNamespace = "Strauss\\Test";
        $replacement = "Prefix\\Strauss\\Test";

        $expected = '$mentionedClass = "\\Prefix\\Strauss\\Test\\Classname";';

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol($originalNamespace, $file);
        $namespaceSymbol->setLocalReplacement($replacement);

        $classSymbol = new ClassSymbol('Strauss\\Test\\Classname', $file, false, $namespaceSymbol);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);
        $discoveredSymbols->add($classSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }

    public function testDoubleLeadingSlashInString(): void
    {
        $contents = '$mentionedClass = "\\\\Strauss\\\\Test\\\\Classname";';

        $originalNamespace = 'Strauss\\Test';
        $replacement = 'Prefix\\Strauss\\Test';

        $expected = '$mentionedClass = "\\\\Prefix\\\\Strauss\\\\Test\\\\Classname";';

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol($originalNamespace, $file);
        $namespaceSymbol->setLocalReplacement($replacement);

        $classSymbol = new ClassSymbol('Strauss\\Test\\Classname', $file, false, $namespaceSymbol);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);
        $discoveredSymbols->add($classSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }

    public function testItReplacesSlashedNamespaceInFunctionParameter(): void
    {
        $contents = <<<'EOD'
class X {
    public function __construct(\net\authorize\api\contract\v1\AnetApiRequestType $request, $responseType) {}
}
EOD;

        $originalNamespace = "net\\authorize\\api\\contract\\v1";
        $replacement = "Prefix\\net\\authorize\\api\\contract\\v1";

        $expected = <<<'EOD'
class X {
    public function __construct(\Prefix\net\authorize\api\contract\v1\AnetApiRequestType $request, $responseType) {}
}
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol($originalNamespace, $file);
        $namespaceSymbol->setLocalReplacement($replacement);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }


    public function testItReplacesNamespaceInFunctionParameterDefaultArgumentValue(): void
    {
        $contents = "function executeWithApiResponse(\$endPoint = \\net\\authorize\\api\\constants\\ANetEnvironment::CUSTOM) {}";

        $originalNamespace = "net\\authorize\\api\constants";
        $replacement = "Prefix\\net\\authorize\\api\constants";

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol($originalNamespace, $file);
        $namespaceSymbol->setLocalReplacement($replacement);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $expected = "function executeWithApiResponse(\$endPoint = \\Prefix\\net\\authorize\\api\\constants\\ANetEnvironment::CUSTOM) {}";

        $this->assertEqualsRN($expected, $result);
    }


    public function testItReplacesNamespaceConcatenatedStringConst(): void
    {
        $contents = "\$this->apiRequest->setClientId(\"sdk-php-\" . \\net\\authorize\\api\\constants\\ANetEnvironment::VERSION);";

        $originalNamespace = "net\\authorize\\api\\constants";
        $replacement = "Prefix\\net\\authorize\\api\\constants";

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol($originalNamespace, $file);
        $namespaceSymbol->setLocalReplacement($replacement);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $expected = "\$this->apiRequest->setClientId(\"sdk-php-\" . \\Prefix\\net\\authorize\\api\\constants\\ANetEnvironment::VERSION);";

        $this->assertEqualsRN($expected, $result);
    }

    /**
     * Another mpdf issue where the class "Mpdf" is in the namespace "Mpdf" and incorrect replacements are being made.
     */
    public function testClassnameNotConfusedWithNamespace(): void
    {

        $contents = '$default_font_size = $mmsize * (Mpdf::SCALE);';
        $expected = $contents;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());
                $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('Mpdf', $file);
        $namespaceSymbol->setLocalReplacement('BrianHenryIE\Strauss\Mpdf');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }

    public function testClassExtendsNamespacedClassIsPrefixed(): void
    {

        $contents = 'class BarcodeException extends \Mpdf\MpdfException {}';
        $expected = 'class BarcodeException extends \BrianHenryIE\Strauss\Mpdf\MpdfException {}';

        $config = $this->createMock(PrefixerConfigInterface::class);

        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());
                $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('Mpdf', $file);
        $namespaceSymbol->setLocalReplacement('BrianHenryIE\Strauss\Mpdf');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }

    /**
     * Prefix namespaced classnames after `new` keyword.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/11
     */
    public function testNewNamespacedClassIsPrefixed(): void
    {

        $contents = '$ioc->register( new \Carbon_Fields\Provider\Container_Condition_Provider() );';
        $expected = '$ioc->register( new \BrianHenryIE\Strauss\Carbon_Fields\Provider\Container_Condition_Provider() );';

        $config = $this->createMock(PrefixerConfigInterface::class);

        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());
                $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('Carbon_Fields\Provider', $file);
        $namespaceSymbol->setLocalReplacement('BrianHenryIE\Strauss\Carbon_Fields\Provider');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }


    /**
     * Prefix namespaced classnames after `static` keyword.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/11
     */
    public function testStaticNamespacedClassIsPrefixed(): void
    {

        $contents = <<<'EOD'
/**
 * @method static \Carbon_Fields\Container\Comment_Meta_Container';
 */
EOD;
        $expected = <<<'EOD'
/**
 * @method static \BrianHenryIE\Strauss\Carbon_Fields\Container\Comment_Meta_Container';
 */
EOD;

        $originalNamespace = 'Carbon_Fields\Container';
        $replacement = 'BrianHenryIE\Strauss\Carbon_Fields\Container';

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol($originalNamespace, $file);
        $namespaceSymbol->setLocalReplacement($replacement);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }

    /**
     * Prefix namespaced classnames after return statement.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/11
     */
    public function testReturnedNamespacedClassIsPrefixed(): void
    {

        $contents = 'return \Carbon_Fields\Carbon_Fields::resolve();';
        $expected = 'return \BrianHenryIE\Strauss\Carbon_Fields\Carbon_Fields::resolve();';

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('Carbon_Fields', $file);
        $namespaceSymbol->setLocalReplacement('BrianHenryIE\\Strauss\\Carbon_Fields');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }

    /**
     * Prefix namespaced classnames between two tabs and colon.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/11
     */
    public function testNamespacedStaticIsPrefixed(): void
    {
        $contents = '		\\Carbon_Fields\\Carbon_Fields::service( \'legacy_storage\' )->enable();';
        $expected = '		\\BrianHenryIE\\Strauss\\Carbon_Fields\\Carbon_Fields::service( \'legacy_storage\' )->enable();';

        $originalNamespace = 'Carbon_Fields';
        $replacement = 'BrianHenryIE\\Strauss\\Carbon_Fields';

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol($originalNamespace, $file);
        $namespaceSymbol->setLocalReplacement($replacement);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }

    /**
     * Sometimes the namespace in a string should be replaced, but sometimes not.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/15
     */
    public function testDoNotReplaceInStringThatIsNotCode(): void
    {
        $originalNamespace = "TrustedLogin";
        $replacement = "Prefix\\TrustedLogin";
        $contents = "esc_html__( 'Learn about TrustedLogin', 'trustedlogin' )";

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol($originalNamespace, $file);
        $namespaceSymbol->setLocalReplacement($replacement);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $expected = "esc_html__( 'Learn about TrustedLogin', 'trustedlogin' )";

        $this->assertEqualsRN($expected, $result);
    }


    /**
     *
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/19
     *
     */
    public function testDoNotReplaceInVariableNames(): void
    {
        $contents = "public static function objclone(\$object) {";

        // NOT public static function objclone($Strauss_Issue19_object) {
        $expected = "public static function objclone(\$object) {";

        $originalClassname = 'object';
        $classnamePrefix = 'Prefixer_Test_';

        $config = $this->getMockConfig();

        $sut = new Prefixer($config, $this->getInMemoryFileSystem());

        $file = Mockery::mock(File::class);
        $file->shouldReceive('getSourcePath')->andReturn('prefixer_test.php');
        $file->shouldReceive('addDiscoveredSymbol');

        $classSymbol = new ClassSymbol($originalClassname, $file);
        $classSymbol->setLocalReplacement($classnamePrefix . $originalClassname);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($classSymbol);

        $result = $sut->replaceInString($discoveredSymbols, $contents);

        $this->assertEqualsRN($expected, $result);
    }

    public function testReplaceConstants(): void
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
{}
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);
        $config->method('getConstantsPrefix')->willReturn('BHMP_');
        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $discoveredSymbols = new DiscoveredSymbols();
        $constants = array('FPDF_VERSION', 'ANOTHER_CONSTANT');
        foreach ($constants as $constant) {
            $constantSymbol = new ConstantSymbol($constant, $file);
            $constantSymbol->setLocalReplacement('BHMP_'.$constant);
            $discoveredSymbols->add($constantSymbol);
        }

        $result = $replacer->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertStringContainsString("define('BHMP_ANOTHER_CONSTANT', '1.83');", $result);
        $this->assertStringContainsString("define('BHMP_ANOTHER_CONSTANT', '1.83');", $result);
    }

    public function testStaticFunctionCallOfNamespacedClassIsPrefixed(): void
    {

        $contents = <<<'EOD'
function __construct() {
    new \ST\StraussTestPackage2();
    \ST\StraussTestPackage2::hello();
    new \ST\StraussTestPackage2();
}
EOD;
        $expected = <<<'EOD'
function __construct() {
    new \StraussTest\ST\StraussTestPackage2();
    \StraussTest\ST\StraussTestPackage2::hello();
    new \StraussTest\ST\StraussTestPackage2();
}
EOD;

        $originalNamespace = 'ST';
        $replacement = 'StraussTest\\ST';

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol($originalNamespace, $file);
        $namespaceSymbol->setLocalReplacement($replacement);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }


    public function testItPrefixesGroupedNamespacedClasses(): void
    {

        $contents = 'use chillerlan\\QRCode\\{QRCode, QRCodeException};';
        $expected = 'use BrianHenryIE\\Strauss\\chillerlan\\QRCode\\{QRCode, QRCodeException};';

        $config = $this->createMock(PrefixerConfigInterface::class);

        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());
                $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('chillerlan\\QRCode', $file);
        $namespaceSymbol->setLocalReplacement('BrianHenryIE\\Strauss\\chillerlan\\QRCode');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }

    /**
     * @see https://github.com/BrianHenryIE/strauss/issues/25
     * @see https://gist.github.com/adrianstaffen/e1df25cd62c17d3f1a4697db6c449034
     */
    public function testStaticSimpleCall(): void
    {

        $config = $this->createMock(PrefixerConfigInterface::class);
        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

        // Simple call.

        $contents = '\ST\StraussTestPackage2::hello();';
        $expected = '\StraussTest\ST\StraussTestPackage2::hello();';

                $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('ST', $file);
        $namespaceSymbol->setLocalReplacement('StraussTest\\ST');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());
        $this->assertEqualsRN($expected, $result);

        $contents = '! \ST\StraussTestPackage2::hello();';
        $expected = '! \StraussTest\ST\StraussTestPackage2::hello();';

                $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('ST', $file);
        $namespaceSymbol->setLocalReplacement('StraussTest\\ST');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());
        $this->assertEqualsRN($expected, $result);
    }


    /**
     * @see https://github.com/BrianHenryIE/strauss/issues/25
     * @see https://gist.github.com/adrianstaffen/e1df25cd62c17d3f1a4697db6c449034
     */
    public function testStaticVariableAssignment(): void
    {

        $config = $this->createMock(PrefixerConfigInterface::class);
        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

        // Variable assignment.
        $contents = '$test1 = \ST\StraussTestPackage2::hello();';
        $expected = '$test1 = \StraussTest\ST\StraussTestPackage2::hello();';

                $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('ST', $file);
        $namespaceSymbol->setLocalReplacement('StraussTest\\ST');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());
        $this->assertEqualsRN($expected, $result);

        $contents = '$test2 = ! \ST\StraussTestPackage2::hello();';
        $expected = '$test2 = ! \StraussTest\ST\StraussTestPackage2::hello();';

                $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('ST', $file);
        $namespaceSymbol->setLocalReplacement('StraussTest\\ST');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());
        $this->assertEqualsRN($expected, $result);
    }


    /**
     * @see https://github.com/BrianHenryIE/strauss/issues/25
     * @see https://gist.github.com/adrianstaffen/e1df25cd62c17d3f1a4697db6c449034
     */
    public function testStaticIfConditionSingle(): void
    {

        $config = $this->createMock(PrefixerConfigInterface::class);
        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

        // If condition: Single.
        $contents = <<<'EOD'
if ( \ST\StraussTestPackage2::hello() ) {
    echo 'hello world';
}
EOD;
        $expected = <<<'EOD'
if ( \StraussTest\ST\StraussTestPackage2::hello() ) {
    echo 'hello world';
}
EOD;

                $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('ST', $file);
        $namespaceSymbol->setLocalReplacement('StraussTest\\ST');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());
        $this->assertEqualsRN($expected, $result);

        $contents = <<<'EOD'
if ( ! \ST\StraussTestPackage2::hello() ) {
    echo 'hello world';
}
EOD;
        $expected = <<<'EOD'
if ( ! \StraussTest\ST\StraussTestPackage2::hello() ) {
    echo 'hello world';
}
EOD;

                $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('ST', $file);
        $namespaceSymbol->setLocalReplacement('StraussTest\\ST');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());
        $this->assertEqualsRN($expected, $result);
    }


    /**
     * @see https://github.com/BrianHenryIE/strauss/issues/25
     * @see https://gist.github.com/adrianstaffen/e1df25cd62c17d3f1a4697db6c449034
     */
    public function testStaticIfConditionMultipleAND(): void
    {

        $config = $this->createMock(PrefixerConfigInterface::class);
        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

// If condition: Multiple (AND).
        $contents = <<<'EOD'
if ( \ST\StraussTestPackage2::hello() && ! \ST\StraussTestPackage2::hello() ) {
    echo 'hello world';
}
EOD;
        $expected = <<<'EOD'
if ( \StraussTest\ST\StraussTestPackage2::hello() && ! \StraussTest\ST\StraussTestPackage2::hello() ) {
    echo 'hello world';
}
EOD;
                $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('ST', $file);
        $namespaceSymbol->setLocalReplacement('StraussTest\\ST');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());
        $this->assertEqualsRN($expected, $result);

        $contents = <<<'EOD'
if ( ! \ST\StraussTestPackage2::hello() && \ST\StraussTestPackage2::hello() ) {
    echo 'hello world';
}
EOD;
        $expected = <<<'EOD'
if ( ! \StraussTest\ST\StraussTestPackage2::hello() && \StraussTest\ST\StraussTestPackage2::hello() ) {
    echo 'hello world';
}
EOD;

                $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('ST', $file);
        $namespaceSymbol->setLocalReplacement('StraussTest\\ST');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());
        $this->assertEqualsRN($expected, $result);
    }


    /**
     * @see https://github.com/BrianHenryIE/strauss/issues/25
     * @see https://gist.github.com/adrianstaffen/e1df25cd62c17d3f1a4697db6c449034
     */
    public function testStaticIfConditionMultipleOR(): void
    {

        $config = $this->createMock(PrefixerConfigInterface::class);
        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

// If condition: Multiple (OR).
        $contents = <<<'EOD'
if ( \ST\StraussTestPackage2::hello() || ! \ST\StraussTestPackage2::hello() ) {
    echo 'hello world';
}
EOD;
        $expected = <<<'EOD'
if ( \StraussTest\ST\StraussTestPackage2::hello() || ! \StraussTest\ST\StraussTestPackage2::hello() ) {
    echo 'hello world';
}
EOD;
                $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('ST', $file);
        $namespaceSymbol->setLocalReplacement('StraussTest\\ST');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());
        $this->assertEqualsRN($expected, $result);

        $contents = <<<'EOD'
if ( ! \ST\StraussTestPackage2::hello() || \ST\StraussTestPackage2::hello() ) {
    echo 'hello world';
}
EOD;
        $expected = <<<'EOD'
if ( ! \StraussTest\ST\StraussTestPackage2::hello() || \StraussTest\ST\StraussTestPackage2::hello() ) {
    echo 'hello world';
}
EOD;

                $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('ST', $file);
        $namespaceSymbol->setLocalReplacement('StraussTest\\ST');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());
        $this->assertEqualsRN($expected, $result);
    }


    /**
     * @see https://github.com/BrianHenryIE/strauss/issues/25
     * @see https://gist.github.com/adrianstaffen/e1df25cd62c17d3f1a4697db6c449034
     */
    public function testStaticArrayNonAssociativeSingle(): void
    {

        $config = $this->createMock(PrefixerConfigInterface::class);
        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

// Array: Non-associative: Single.
        $contents = <<<'EOD'
$arr1 = array(
    \ST\StraussTestPackage2::hello(),
    ! \ST\StraussTestPackage2::hello(),
);
EOD;
        $expected = <<<'EOD'
$arr1 = array(
    \StraussTest\ST\StraussTestPackage2::hello(),
    ! \StraussTest\ST\StraussTestPackage2::hello(),
);
EOD;

                $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('ST', $file);
        $namespaceSymbol->setLocalReplacement('StraussTest\\ST');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());
        $this->assertEqualsRN($expected, $result);
    }


    /**
     * @see https://github.com/BrianHenryIE/strauss/issues/25
     * @see https://gist.github.com/adrianstaffen/e1df25cd62c17d3f1a4697db6c449034
     */
    public function testStaticArrayNonAssociativeMultipleAND(): void
    {

        $config = $this->createMock(PrefixerConfigInterface::class);
        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

// Array: Non-associative: Multiple (AND).
        $contents = <<<'EOD'
$arr2 = array(
    \ST\StraussTestPackage2::hello() && ! \ST\StraussTestPackage2::hello(),
    ! \ST\StraussTestPackage2::hello() && \ST\StraussTestPackage2::hello(),
);
EOD;
        $expected = <<<'EOD'
$arr2 = array(
    \StraussTest\ST\StraussTestPackage2::hello() && ! \StraussTest\ST\StraussTestPackage2::hello(),
    ! \StraussTest\ST\StraussTestPackage2::hello() && \StraussTest\ST\StraussTestPackage2::hello(),
);
EOD;

                $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('ST', $file);
        $namespaceSymbol->setLocalReplacement('StraussTest\\ST');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());
        $this->assertEqualsRN($expected, $result);
    }


    /**
     * @see https://github.com/BrianHenryIE/strauss/issues/25
     * @see https://gist.github.com/adrianstaffen/e1df25cd62c17d3f1a4697db6c449034
     */
    public function testStaticArrayNonAssociationMultipleOR(): void
    {

        $config = $this->createMock(PrefixerConfigInterface::class);
        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

// Array: Non-associative: Multiple (OR).
        $contents = <<<'EOD'
$arr3 = array(
    \ST\StraussTestPackage2::hello() || ! \ST\StraussTestPackage2::hello(),
    ! \ST\StraussTestPackage2::hello() || \ST\StraussTestPackage2::hello(),
);
EOD;
        $expected = <<<'EOD'
$arr3 = array(
    \StraussTest\ST\StraussTestPackage2::hello() || ! \StraussTest\ST\StraussTestPackage2::hello(),
    ! \StraussTest\ST\StraussTestPackage2::hello() || \StraussTest\ST\StraussTestPackage2::hello(),
);
EOD;

                $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('ST', $file);
        $namespaceSymbol->setLocalReplacement('StraussTest\\ST');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());
        $this->assertEqualsRN($expected, $result);
    }


    /**
     * @see https://github.com/BrianHenryIE/strauss/issues/25
     * @see https://gist.github.com/adrianstaffen/e1df25cd62c17d3f1a4697db6c449034
     */
    public function testStaticArrayAssociativeSingle(): void
    {

        $config = $this->createMock(PrefixerConfigInterface::class);
        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

// Array: Associative: Single.
        $contents = <<<'EOD'
$assoc_arr1 = array(
    'one' => \ST\StraussTestPackage2::hello(),
    'two' => ! \ST\StraussTestPackage2::hello(),
);
EOD;
        $expected = <<<'EOD'
$assoc_arr1 = array(
    'one' => \StraussTest\ST\StraussTestPackage2::hello(),
    'two' => ! \StraussTest\ST\StraussTestPackage2::hello(),
);
EOD;

                $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('ST', $file);
        $namespaceSymbol->setLocalReplacement('StraussTest\\ST');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());
        $this->assertEqualsRN($expected, $result);
    }


    /**
     * @see https://github.com/BrianHenryIE/strauss/issues/25
     * @see https://gist.github.com/adrianstaffen/e1df25cd62c17d3f1a4697db6c449034
     */
    public function testStaticArrayAssociativeMultipleAND(): void
    {

        $config = $this->createMock(PrefixerConfigInterface::class);
        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

// Array: Associative: Multiple (AND).
        $contents = <<<'EOD'
$assoc_arr1 = array(
    'one' => \ST\StraussTestPackage2::hello() && ! \ST\StraussTestPackage2::hello(),
    'two' => ! \ST\StraussTestPackage2::hello() && \ST\StraussTestPackage2::hello(),
);
EOD;
        $expected = <<<'EOD'
$assoc_arr1 = array(
    'one' => \StraussTest\ST\StraussTestPackage2::hello() && ! \StraussTest\ST\StraussTestPackage2::hello(),
    'two' => ! \StraussTest\ST\StraussTestPackage2::hello() && \StraussTest\ST\StraussTestPackage2::hello(),
);
EOD;
                $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('ST', $file);
        $namespaceSymbol->setLocalReplacement('StraussTest\\ST');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());
        $this->assertEqualsRN($expected, $result);
    }


    /**
     * @see https://github.com/BrianHenryIE/strauss/issues/25
     * @see https://gist.github.com/adrianstaffen/e1df25cd62c17d3f1a4697db6c449034
     */
    public function testStaticArrayAssociativeMultipleOR(): void
    {

        $config = $this->createMock(PrefixerConfigInterface::class);
        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

// Array: Associative: Multiple (OR).
        $contents = <<<'EOD'
$assoc_arr1 = array(
    'one' => \ST\StraussTestPackage2::hello() || ! \ST\StraussTestPackage2::hello(),
    'two' => ! \ST\StraussTestPackage2::hello() || \ST\StraussTestPackage2::hello(),
);
EOD;
        $expected = <<<'EOD'
$assoc_arr1 = array(
    'one' => \StraussTest\ST\StraussTestPackage2::hello() || ! \StraussTest\ST\StraussTestPackage2::hello(),
    'two' => ! \StraussTest\ST\StraussTestPackage2::hello() || \StraussTest\ST\StraussTestPackage2::hello(),
);
EOD;

                $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('ST', $file);
        $namespaceSymbol->setLocalReplacement('StraussTest\\ST');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());
        $this->assertEqualsRN($expected, $result);
    }


    /**
     * @see https://github.com/BrianHenryIE/strauss/issues/26
     */
    public function testDoublePrefixBug(): void
    {
        $this->markTestIncomplete();

        $config = $this->getMockConfig();

        $sut = new Prefixer($config, $this->getInMemoryFileSystem());

        $contents1 = <<<'EOD'
namespace ST;
class StraussTestPackage {
	public function __construct() {
	}
}
EOD;
        $expected1 = <<<'EOD'
namespace StraussTest\ST;
class StraussTestPackage {
	public function __construct() {
	}
}
EOD;



        $contents2 = <<<'EOD'
namespace ST\Namespace;
class StraussTestPackage2
{
    public function __construct()
    {
        $one = '\ST\Namespace';
        $two = '\ST\Namespace\StraussTestPackage2';
    }
}
EOD;
        $expected2 = <<<'EOD'
namespace StraussTest\ST\Namespace;
class StraussTestPackage2
{
    public function __construct()
    {
        $one = '\StraussTest\ST\Namespace';
        $two = '\StraussTest\ST\Namespace\StraussTestPackage2';
    }
}
EOD;

        $originalNamespace = 'ST\\Namespace';
        $replacement = 'StraussTest\\ST\\Namespace';

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol($originalNamespace, $file);
        $namespaceSymbol->setLocalReplacement($replacement);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents2);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected2, $result);
    }

    /**
     * A prefixed classname was being replaced inside a namespace name.
     *
     * namespace Symfony\Polyfill\Intl\Normalizer_Test_Normalizer;
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/27
     *
     * @author BrianHenryIE
     */
    public function testItDoesNotPrefixClassnameInsideNamespaceName(): void
    {

        $contents = <<<'EOD'
namespace Symfony\Polyfill\Intl\Normalizer;
class NA
{

}
EOD;

        $originalClassname = 'Normalizer';
        $classnamePrefix = 'Prefixer_Test_';

        $config = $this->getMockConfig();

        $sut = new Prefixer($config, $this->getInMemoryFileSystem());

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $classSymbol = new ClassSymbol($originalClassname, $file);
        $classSymbol->setLocalReplacement($classnamePrefix . $originalClassname);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($classSymbol);

        $result = $sut->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertEqualsRN($contents, $result);
    }

    /**
     * class Normalizer_Test_Normalizer extends Normalizer_Test\Symfony\Polyfill\Intl\Normalizer_Test_Normalizer\Normalizer
     *
     * @throws \Exception
     */
    public function testItDoesNotPrefixClassnameInsideInsideNamespaceName(): void
    {

        $contents = <<<'EOD'
class Normalizer extends Symfony\Polyfill\Intl\Normalizer\Foo
{

}
EOD;

        $expected = <<<'EOD'
class Prefixer_Test_Normalizer extends Symfony\Polyfill\Intl\Normalizer\Foo
{

}
EOD;

        $originalClassname = 'Normalizer';
        $classnamePrefix = 'Prefixer_Test_';

        $config = $this->getMockConfig();

        $sut = new Prefixer($config, $this->getInMemoryFileSystem());

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $classSymbol = new ClassSymbol($originalClassname, $file);
        $classSymbol->setLocalReplacement($classnamePrefix . $originalClassname);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($classSymbol);

        $result = $sut->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertEqualsRN($expected, $result);
    }

    /**
     * class Normalizer_Test_Normalizer extends Normalizer_Test\Symfony\Polyfill\Intl\Normalizer_Test_Normalizer\Normalizer
     *
     * @throws \Exception
     */
    public function testItDoesNotPrefixClassnameInsideEndNamespaceName(): void
    {

        $contents = <<<'EOD'
class Normalizer extends Symfony\Polyfill\Intl\Foo\Normalizer
{

}
EOD;

        $expected = <<<'EOD'
class Prefixer_Test_Normalizer extends Symfony\Polyfill\Intl\Foo\Normalizer
{

}
EOD;

        $originalClassname = 'Normalizer';
        $classnamePrefix = 'Prefixer_Test_';

        $config = $this->getMockConfig();

        $sut = new Prefixer($config, $this->getInMemoryFileSystem());

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $classSymbol = new ClassSymbol($originalClassname, $file);
        $classSymbol->setLocalReplacement($classnamePrefix . $originalClassname);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($classSymbol);

        $result = $sut->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertEqualsRN($expected, $result);
    }


    /**
     *
     *
     * @throws \Exception
     */
    public function testItDoesNotPrefixClassDeclarationInsideNamespace(): void
    {

        $contents = <<<'EOD'
<?php
namespace Symfony\Polyfill\Intl\Normalizer;

class Normalizer
{
}
EOD;

        $expected = <<<'EOD'
<?php
namespace Symfony\Polyfill\Intl\Normalizer;

class Normalizer
{
}
EOD;

        $originalClassname = 'Normalizer';
        $classnamePrefix = 'Prefixer_Test_';

        $config = $this->getMockConfig();

        $sut = new Prefixer($config, $this->getInMemoryFileSystem());

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $classSymbol = new ClassSymbol($originalClassname, $file);
        $classSymbol->setLocalReplacement($classnamePrefix . $originalClassname);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($classSymbol);

        $result = $sut->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertEqualsRN($expected, $result);
    }

    /**
     * @see https://github.com/BrianHenryIE/strauss/issues/48
     * @see https://php.watch/versions/8.1/ReturnTypeWillChange
     */
    public function testItDoesNotPrefixReturnTypeWillChangeAsClassname(): void
    {

        $contents = <<<'EOD'
namespace Symfony\Polyfill\Intl\Normalizer;
class NA
{
	#[\ReturnTypeWillChange]
    public function offsetGet(mixed $offset) {}
}
EOD;

        $classnamePrefix = 'Normalizer_Test_';

        $config = $this->createMock(PrefixerConfigInterface::class);
        $config->method("getClassmapPrefix")->willReturn($classnamePrefix);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $discoveredSymbols = new DiscoveredSymbols();
        $classSymbol = new ClassSymbol('Normalizer', $file);
        $classSymbol->setLocalReplacement('Normalizer_Test_Normalizer');
        $discoveredSymbols->add($classSymbol);

        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

        $result = $replacer->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertEqualsRN($contents, $result);
    }

    /**
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/36
     *
     */
    public function testItReplacesStaticInsideSquareArray(): void
    {

        $contents = <<<'EOD'
namespace ST;
class StraussTestPackage {
	public function __construct() {
		$arr = array();

		$arr[ ( new \ST\StraussTestPackage2() )->test() ] = true;

		$arr[ \ST\StraussTestPackage2::test2() ] = true;
	}
}
EOD;

        $expected = <<<'EOD'
namespace StraussTest\ST;
class StraussTestPackage {
	public function __construct() {
		$arr = array();

		$arr[ ( new \StraussTest\ST\StraussTestPackage2() )->test() ] = true;

		$arr[ \StraussTest\ST\StraussTestPackage2::test2() ] = true;
	}
}
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('ST', $file);
        $namespaceSymbol->setLocalReplacement('StraussTest\\ST');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }

    /**
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/44
     *
     */
    public function testItReplacesStaticInsideMultilineTernary(): void
    {

        $contents = <<<'EOD'
namespace GuzzleHttp;

use Psr\Http\Message\MessageInterface;

final class BodySummarizer implements BodySummarizerInterface
{
    /**
     * Returns a summarized message body.
     */
    public function summarize(MessageInterface $message): ?string
    {
        return $this->truncateAt === null
            ? \GuzzleHttp\Psr7\Message::bodySummary($message)
            : \GuzzleHttp\Psr7\Message::bodySummary($message, $this->truncateAt);
    }
}
EOD;

        $expected = <<<'EOD'
namespace StraussTest\GuzzleHttp;

use Psr\Http\Message\MessageInterface;

final class BodySummarizer implements BodySummarizerInterface
{
    /**
     * Returns a summarized message body.
     */
    public function summarize(MessageInterface $message): ?string
    {
        return $this->truncateAt === null
            ? \StraussTest\GuzzleHttp\Psr7\Message::bodySummary($message)
            : \StraussTest\GuzzleHttp\Psr7\Message::bodySummary($message, $this->truncateAt);
    }
}
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

                $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('GuzzleHttp', $file);
        $namespaceSymbol->setLocalReplacement('StraussTest\\GuzzleHttp');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }

    /**
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/65
     * @see vendor/aws/aws-sdk-php/src/Endpoint/UseDualstackEndpoint/Configuration.php
     */
    public function testItPrefixesNamespacedFunctionUse(): void
    {
        $contents = <<<'EOD'
namespace Aws\Endpoint\UseDualstackEndpoint;

use Aws;
use Aws\Endpoint\UseDualstackEndpoint\Exception\ConfigurationException;

class Configuration implements ConfigurationInterface
{
    private $useDualstackEndpoint;

    public function __construct($useDualstackEndpoint, $region)
    {
        $this->useDualstackEndpoint = Aws\boolean_value($useDualstackEndpoint);
        if (is_null($this->useDualstackEndpoint)) {
            throw new ConfigurationException("'use_dual_stack_endpoint' config option"
                . " must be a boolean value.");
        }
        if ($this->useDualstackEndpoint == true
            && (strpos($region, "iso-") !== false || strpos($region, "-iso") !== false)
        ) {
            throw new ConfigurationException("Dual-stack is not supported in ISO regions");
        }
    }
}
EOD;

        $expected = <<<'EOD'
namespace StraussTest\Aws\Endpoint\UseDualstackEndpoint;

use StraussTest\Aws;
use StraussTest\Aws\Endpoint\UseDualstackEndpoint\Exception\ConfigurationException;

class Configuration implements ConfigurationInterface
{
    private $useDualstackEndpoint;

    public function __construct($useDualstackEndpoint, $region)
    {
        $this->useDualstackEndpoint = \StraussTest\Aws\boolean_value($useDualstackEndpoint);
        if (is_null($this->useDualstackEndpoint)) {
            throw new ConfigurationException("'use_dual_stack_endpoint' config option"
                . " must be a boolean value.");
        }
        if ($this->useDualstackEndpoint == true
            && (strpos($region, "iso-") !== false || strpos($region, "-iso") !== false)
        ) {
            throw new ConfigurationException("Dual-stack is not supported in ISO regions");
        }
    }
}
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $namespaceSymbol = new NamespaceSymbol('Aws', $file);
        $namespaceSymbol->setLocalReplacement('StraussTest\\Aws');
        $discoveredSymbols->add($namespaceSymbol);

        $classSymbol = new ClassSymbol('Aws\Endpoint\UseDualstackEndpoint\Configurations', $file, false, $namespaceSymbol);
        $discoveredSymbols->add($classSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }


    /**
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/75
     *
     */
    public function testPrefixUseFunction(): void
    {

        $contents = <<<'EOD'
namespace Chophper;

use function Chophper\some_func;

some_func();
EOD;

        $expected = <<<'EOD'
namespace StraussTest\Chophper;

use function StraussTest\Chophper\some_func;

some_func();
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $namespaceSymbol = new NamespaceSymbol('Chophper', $file);
        $namespaceSymbol->setLocalReplacement('StraussTest\\Chophper');
        $discoveredSymbols->add($namespaceSymbol);

        $functionSymbol = new FunctionSymbol('Chophper\some_func', $file, $namespaceSymbol);
        $discoveredSymbols->add($functionSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }

    /**
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/66
     *
     */
    public function testPrefixGlobalClassUse(): void
    {

        $contents = <<<'EOD'
<?php
namespace WPGraphQL\Registry\Utils;

use WPGraphQL;
EOD;

        $expected = <<<'EOD'
<?php
namespace StraussTest\WPGraphQL\Registry\Utils;

use StraussTest_WPGraphQL as WPGraphQL;
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);
        $config->method("getClassmapPrefix")->willReturn('StraussTest_');

        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $namespaceSymbol = new NamespaceSymbol('WPGraphQL\Registry\Utils', $file);
        $namespaceSymbol->setLocalReplacement('StraussTest\WPGraphQL\Registry\Utils');
        $discoveredSymbols->add($namespaceSymbol);

        $classSymbol = new ClassSymbol('WPGraphQL', $file);
        $classSymbol->setLocalReplacement('StraussTest_WPGraphQL');
        $discoveredSymbols->add($classSymbol);

        $result = $replacer->replaceInString(
            $discoveredSymbols,
            $contents,
            $file
        );

        $this->assertEqualsRN($expected, $result);
    }

    /**
     * @see https://github.com/BrianHenryIE/strauss/issues/80
     */
    public function test_prefix_no_newline_after_opening_php_replace_namespace(): void
    {
        $filesystem = $this->getInMemoryFileSystem();

        $contents = <<<'EOD'
<?php namespace League\OAuth2\Client\Provider;

use League\OAuth2\Client\Tool\ArrayAccessorTrait;
EOD;

        $expected = <<<'EOD'
<?php namespace Company\Project\League\OAuth2\Client\Provider;

use Company\Project\League\OAuth2\Client\Tool\ArrayAccessorTrait;
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'project/vendor/league/oauth2/provideruse.php',
            'league/oauth2/provideruse.php',
            'project/vendor-prefixed/league/oauth2/provideruse.php'
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $providerNamespaceSymbol =new NamespaceSymbol('League\\OAuth2\\Client\\Provider');
        $providerNamespaceSymbol->setLocalReplacement('Company\\Project\\League\\OAuth2\\Client\\Provider');
        $discoveredSymbols->add($providerNamespaceSymbol);

        $toolNamespaceSymbol = new NamespaceSymbol('League\\OAuth2\\Client\\Tool');
        $toolNamespaceSymbol->setLocalReplacement('Company\\Project\\League\\OAuth2\\Client\\Tool');
        $discoveredSymbols->add($toolNamespaceSymbol);

        $traitSymbol = new TraitSymbol('League\OAuth2\Client\Tool\ArrayAccessorTrait', $file, $toolNamespaceSymbol);
        $discoveredSymbols->add($traitSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }

    /**
     * @covers ::findGlobalSymbolsPositionsInComment
     * @covers ::findGlobalSymbolPositionInComment
     *
     * A \Global_Class in PHPDoc was capturing far beyond what it should and replacing the entire function.
     */
    public function test_global_class_phpdoc_end_delimiter(): void
    {

        $contents = <<<'EOD'
<?php
namespace Company\Project;

class Calendar {
	/**
	 * @return \Google_Client|WP_Error
	 */
	public function get_google_client() {
		return $this->get_google_connection()->get_client();
	}
}
EOD;

        $expected = <<<'EOD'
<?php
namespace Company\Project;

class Calendar {
	/**
	 * @return \Prefixer_Test_Google_Client|WP_Error
	 */
	public function get_google_client() {
		return $this->get_google_connection()->get_client();
	}
}
EOD;

        $originalClassname = 'Google_Client';
        $classnamePrefix = 'Prefixer_Test_';

        $config = $this->getMockConfig();

        $sut = new Prefixer($config, $this->getInMemoryFileSystem());

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $classSymbol = new ClassSymbol($originalClassname, $file);
        $classSymbol->setLocalReplacement($classnamePrefix . $originalClassname);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($classSymbol);

        $result = $sut->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertEqualsRN($expected, $result);
    }

    /**
     * @see https://github.com/BrianHenryIE/strauss/issues/83
     * @see vendor-prefixed/aws/aws-sdk-php/src/ClientResolver.php:955
     */
    public function testPrefixesFullNamespaceInInstanceOf(): void
    {
        $contents = <<<'EOD'
<?php
namespace Aws;

class ClientResolver
{
	public static function _apply_user_agent($inputUserAgent, array &$args, HandlerList $list)
    {
            if (($args['endpoint_discovery'] instanceof \Aws\EndpointDiscovery\Configuration
                && $args['endpoint_discovery']->isEnabled())
            ) {

            }
	}
}
EOD;

        $expected = <<<'EOD'
<?php
namespace Company\Project\Aws;

class ClientResolver
{
	public static function _apply_user_agent($inputUserAgent, array &$args, HandlerList $list)
    {
            if (($args['endpoint_discovery'] instanceof \Company\Project\Aws\EndpointDiscovery\Configuration
                && $args['endpoint_discovery']->isEnabled())
            ) {

            }
	}
}
EOD;
        $originalNamespace = 'Aws\\EndpointDiscovery';
        $replacement = 'Company\\Project\\Aws\\EndpointDiscovery';

        $config = $this->createMock(PrefixerConfigInterface::class);

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('Aws', $file);
        $namespaceSymbol->setLocalReplacement('Company\\Project\\Aws');

        $namespaceSymbol2 = new NamespaceSymbol($originalNamespace, $file);
        $namespaceSymbol2->setLocalReplacement($replacement);

        $classSymbol = new ClassSymbol('Aws\\ClientResolver', $file, false, $namespaceSymbol2);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);
        $discoveredSymbols->add($namespaceSymbol2);
        $discoveredSymbols->add($classSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }

    /**
     * @see https://github.com/BrianHenryIE/strauss/issues/114
     * @see vendor-prefixed/aws/aws-sdk-php/src/Configuration/ConfigurationResolver.php:121
     */
    public function testPrefixesFQDNWithMutedErrors(): void
    {
        $contents = <<<'EOD'
<?php
namespace Aws;

class ConfigurationResolver
{
	public static function ini(
        $key,
        $expectedType,
        $profile = null,
        $filename = null,
        $options = []
    ){
        $filename = $filename ?: (self::getDefaultConfigFilename());
        $profile = $profile ?: (getenv(self::ENV_PROFILE) ?: 'default');

        if (!@is_readable($filename)) {
            return null;
        }
        // Use INI_SCANNER_NORMAL instead of INI_SCANNER_TYPED for PHP 5.5 compatibility
        //TODO change after deprecation
        $data = @\Aws\parse_ini_file($filename, true, INI_SCANNER_NORMAL);

		// ...
    }
}
EOD;

        $expected = <<<'EOD'
<?php
namespace Company\Project\Aws;

class ConfigurationResolver
{
	public static function ini(
        $key,
        $expectedType,
        $profile = null,
        $filename = null,
        $options = []
    ){
        $filename = $filename ?: (self::getDefaultConfigFilename());
        $profile = $profile ?: (getenv(self::ENV_PROFILE) ?: 'default');

        if (!@is_readable($filename)) {
            return null;
        }
        // Use INI_SCANNER_NORMAL instead of INI_SCANNER_TYPED for PHP 5.5 compatibility
        //TODO change after deprecation
        $data = @\Company\Project\Aws\parse_ini_file($filename, true, INI_SCANNER_NORMAL);

		// ...
    }
}
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

                $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('Aws', $file);
        $namespaceSymbol->setLocalReplacement('Company\\Project\\Aws');

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }

    public function testPrefixesAliasedGlobalClass(): void
    {
        $contents = <<<'EOD'
<?php

use GlobalClass as Alias;

class MyClass {

}
EOD;
        $expected = <<<'EOD'
<?php

use Prefixer_Test_GlobalClass as Alias;

class MyClass {

}
EOD;

        $originalClassname = "GlobalClass";
        $classnamePrefix = "Prefixer_Test_";

        $config = $this->getMockConfig();

        $sut = new Prefixer($config, $this->getInMemoryFileSystem());

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $classSymbol = new ClassSymbol($originalClassname, $file);
        $classSymbol->setLocalReplacement($classnamePrefix . $originalClassname);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($classSymbol);

        $result = $sut->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertEqualsRN($expected, $result);
    }

    /**
     * @covers ::replaceFunctions
     */
    public function testReplaceFunctions(): void
    {
        $contents = <<<'EOD'
<?php
if (! function_exists('append_config')) {
    function append_config(array $array)
    {
        return $array;
    }
}

// elsewhere

$value = append_config($myArray);

// without assignment
 append_config($myArray);

// callable
call_user_func('append_config', $myArray);
call_user_func_array(
	'append_config',
	$myArray
);
forward_static_call('append_config', $myArray);
forward_static_call_array('append_config', $myArray);
register_shutdown_function('append_config');
register_tick_function('append_config' , $myArray);
unregister_tick_function( 'append_config');
EOD;
        $expected = <<<'EOD'
<?php
if (! function_exists('myprefix_append_config')) {
    function myprefix_append_config(array $array)
    {
        return $array;
    }
}

// elsewhere

$value = myprefix_append_config($myArray);

// without assignment
 myprefix_append_config($myArray);

// callable
call_user_func('myprefix_append_config', $myArray);
call_user_func_array(
	'myprefix_append_config',
	$myArray
);
forward_static_call('myprefix_append_config', $myArray);
forward_static_call_array('myprefix_append_config', $myArray);
register_shutdown_function('myprefix_append_config');
register_tick_function('myprefix_append_config' , $myArray);
unregister_tick_function( 'myprefix_append_config');
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $symbols = new DiscoveredSymbols();

        $symbol = new FunctionSymbol('append_config', $file);
        $symbol->setLocalReplacement('myprefix_append_config');
        $symbols->add($symbol);

        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

        $result = $replacer->replaceInString($symbols, $contents, $file);

        $this->assertEqualsRN($expected, $result);
    }

    /**
     * @covers ::prepareRelativeNamespaces
     */
    public function testPrepareRelativeNamespaces(): void
    {

        $contents = <<<'EOD'
<?php

namespace Latte\Loaders;

use Latte;

/**
 * Template loader.
 */
class FileLoader implements Latte\Loader
{
	use Latte\Strict;

	/**
	 * Returns template source code.
	 */
	public function getContent($fileName): string
	{
		$file = $this->baseDir . $fileName;
		if ($this->baseDir && !Latte\Helpers::startsWith($this->normalizePath($file), $this->baseDir)) {
			throw new Latte\RuntimeException("Template '$file' is not within the allowed path '{$this->baseDir}'.");

		} elseif (!is_file($file)) {
			throw new Latte\RuntimeException("Missing template file '$file'.");

		} elseif ($this->isExpired($fileName, time())) {
			if (@touch($file) === false) {
				trigger_error("File's modification time is in the future. Cannot update it: " . error_get_last()['message'], E_USER_WARNING);
			}
		}

		return $this->getFileSystem()->read($file);
	}
}
EOD;

        $expected = <<<'EOD'
<?php

namespace Latte\Loaders;

use Latte;

/**
 * Template loader.
 */
class FileLoader implements \Latte\Loader
{
	use \Latte\Strict;

	/**
	 * Returns template source code.
	 */
	public function getContent($fileName): string
	{
		$file = $this->baseDir . $fileName;
		if ($this->baseDir && !\Latte\Helpers::startsWith($this->normalizePath($file), $this->baseDir)) {
			throw new \Latte\RuntimeException("Template '{$file}' is not within the allowed path '{$this->baseDir}'.");

		} elseif (!is_file($file)) {
			throw new \Latte\RuntimeException("Missing template file '{$file}'.");

		} elseif ($this->isExpired($fileName, time())) {
			if (@touch($file) === false) {
				trigger_error("File's modification time is in the future. Cannot update it: " . error_get_last()['message'], E_USER_WARNING);
			}
		}

		return $this->getFileSystem()->read($file);
	}
}
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('Latte', $file);

        $symbols = new DiscoveredSymbols();
        $symbols->add($namespaceSymbol);

        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

        $result = $replacer->replaceInString($symbols, $contents, $file);

        $this->assertEqualsRemoveBlankLinesLeadingWhitespace($expected, $result);
    }

    public function test_dont_double_slash(): void
    {

        $contents = <<<'EOD'
<?php

namespace GuzzleHttp;

use Psr\Http\Message\MessageInterface;

final class BodySummarizer implements BodySummarizerInterface
{
    /**
     * @var int|null
     */
    private $truncateAt;

    public function __construct(int $truncateAt = null)
    {
        $this->truncateAt = $truncateAt;
    }

    /**
     * Returns a summarized message body.
     */
    public function summarize(MessageInterface $message): ?string
    {
        return $this->truncateAt === null
            ? \GuzzleHttp\Psr7\Message::bodySummary($message)
            : \GuzzleHttp\Psr7\Message::bodySummary($message, $this->truncateAt);
    }
}
EOD;

        $expected = <<<'EOD'
<?php

namespace Strauss\Test\GuzzleHttp;

use Psr\Http\Message\MessageInterface;

final class BodySummarizer implements BodySummarizerInterface
{
    /**
     * @var int|null
     */
    private $truncateAt;

    public function __construct(int $truncateAt = null)
    {
        $this->truncateAt = $truncateAt;
    }

    /**
     * Returns a summarized message body.
     */
    public function summarize(MessageInterface $message): ?string
    {
        return $this->truncateAt === null
            ? \Strauss\Test\GuzzleHttp\Psr7\Message::bodySummary($message)
            : \Strauss\Test\GuzzleHttp\Psr7\Message::bodySummary($message, $this->truncateAt);
    }
}
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol('GuzzleHttp', $file);
        $namespaceSymbol->setLocalReplacement('Strauss\\Test\\GuzzleHttp');

        $symbols = new DiscoveredSymbols();
        $symbols->add($namespaceSymbol);

        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

        $result = $replacer->replaceInString($symbols, $contents, $file);

        $this->assertEqualsRemoveBlankLinesLeadingWhitespace($expected, $result);
    }

    public function test_relative_namespace_in_function_parameter(): void
    {

        $contents = <<<'EOD'
<?php

namespace Latte\Macros;

use Latte;

class BlockMacros extends MacroSet
{

	public static function install(Latte\Compiler $compiler): void
	{

	}
}
EOD;

        $expected = <<<'EOD'
<?php

namespace Strauss\Test\Latte\Macros;

use Strauss\Test\Latte;

class BlockMacros extends MacroSet
{

	public static function install(\Strauss\Test\Latte\Compiler $compiler): void
	{

	}
}
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $namespaceSymbol = new NamespaceSymbol('Latte', $file);
        $namespaceSymbol->setLocalReplacement('Strauss\\Test\\Latte');
        $discoveredSymbols->add($namespaceSymbol);

        $classSymbol = new ClassSymbol('Latte\Macros\BlockMacros', $file, false, $namespaceSymbol);
        $discoveredSymbols->add($classSymbol);

        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

        $result = $replacer->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertEqualsRemoveBlankLinesLeadingWhitespace($expected, $result);
    }

    public function test_relative_namespace_constant(): void
    {

        $contents = <<<'EOD'
<?php

namespace Latte\Macros;

use Latte;

class BlockMacros extends MacroSet
{
	public function macroBlock(MacroNode $node, PhpWriter $writer): string
	{
		if (Helpers::startsWith((string) $node->context[1], Latte\Compiler::CONTEXT_HTML_ATTRIBUTE)) {
			$node->context[1] = '';
			$node->modifiers .= '|escape';
		} elseif ($node->modifiers) {
			$node->modifiers .= '|escape';
		}
	}
}
EOD;

        $expected = <<<'EOD'
<?php

namespace Strauss\Test\Latte\Macros;

use Strauss\Test\Latte;

class BlockMacros extends MacroSet
{
	public function macroBlock(MacroNode $node, PhpWriter $writer): string
	{
		if (Helpers::startsWith((string) $node->context[1], \Strauss\Test\Latte\Compiler::CONTEXT_HTML_ATTRIBUTE)) {
			$node->context[1] = '';
			$node->modifiers .= '|escape';
		} elseif ($node->modifiers) {
			$node->modifiers .= '|escape';
		}
	}
}
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $namespaceSymbol = new NamespaceSymbol('Latte', $file);
        $namespaceSymbol->setLocalReplacement('Strauss\\Test\\Latte');
        $discoveredSymbols->add($namespaceSymbol);

        $classSymbol = new ClassSymbol('Latte\Macros\BlockMacros', $file, false, $namespaceSymbol);
        $discoveredSymbols->add($classSymbol);

        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

        $result = $replacer->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertEqualsRemoveBlankLinesLeadingWhitespace($expected, $result);
    }

    public function test_relative_phpdoc(): void
    {

        $contents = <<<'EOD'
<?php

namespace Latte\Macros;

use Latte;
use Latte\CompileException;
use Latte\MacroNode;

class MacroSet implements Latte\Macro
{
	/** @var Latte\Compiler */
	private $compiler;
}
EOD;

        $expected = <<<'EOD'
<?php

namespace Strauss\Test\Latte\Macros;

use Strauss\Test\Latte;
use Strauss\Test\Latte\CompileException;
use Strauss\Test\Latte\MacroNode;

class MacroSet implements \Strauss\Test\Latte\Macro
{
	/** @var \Strauss\Test\Latte\Compiler */
	private $compiler;
}
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $namespaceSymbol = new NamespaceSymbol('Latte', $file);
        $namespaceSymbol->setLocalReplacement('Strauss\\Test\\Latte');
        $discoveredSymbols->add($namespaceSymbol);

        $classSymbol = new ClassSymbol('Latte\Macros\MacroSet', $file, false, $namespaceSymbol);
        $discoveredSymbols->add($classSymbol);

        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

        $result = $replacer->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertEqualsRemoveBlankLinesLeadingWhitespace($expected, $result);
    }

    public function test_relative_return_type(): void
    {
        $contents = <<<'EOD'
<?php

namespace Latte\Macros;

use Latte;
use Latte\CompileException;
use Latte\MacroNode;

class MacroSet implements Latte\Macro
{
	public function getCompiler(): Latte\Compiler
	{
		return $this->compiler;
	}
}
EOD;

        $expected = <<<'EOD'
<?php

namespace Strauss\Test\Latte\Macros;

use Strauss\Test\Latte;
use Strauss\Test\Latte\CompileException;
use Strauss\Test\Latte\MacroNode;

class MacroSet implements \Strauss\Test\Latte\Macro
{
	public function getCompiler(): \Strauss\Test\Latte\Compiler
	{
		return $this->compiler;
	}
}
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $namespaceSymbol = new NamespaceSymbol('Latte', $file);
        $namespaceSymbol->setLocalReplacement('Strauss\\Test\\Latte');
        $discoveredSymbols->add($namespaceSymbol);

        $classSymbol = new ClassSymbol('Latte\Macros\MacroSet', $file, false, $namespaceSymbol);
        $discoveredSymbols->add($classSymbol);

        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

        $result = $replacer->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertEqualsRemoveBlankLinesLeadingWhitespace($expected, $result);
    }

    public function test_relative_static_property(): void
    {

        $contents = <<<'EOD'
<?php

namespace Latte\Runtime;

use Latte;
use Latte\Engine;
use Latte\RuntimeException;
use Nette;
use function is_array, is_string, count, strlen;

class Filters
{
	public static function checkTagSwitch(string $orig, $new): void
	{
		$new = strtolower($new);
		if (
			$new === 'style' || $new === 'script'
			|| isset(Latte\Helpers::$emptyElements[strtolower($orig)]) !== isset(Latte\Helpers::$emptyElements[$new])
		) {
			throw new Latte\RuntimeException("Forbidden tag <$orig> change to <$new>.");
		}
	}
}
EOD;

        $expected = <<<'EOD'
<?php

namespace Strauss\Test\Latte\Runtime;

use Strauss\Test\Latte;
use Strauss\Test\Latte\Engine;
use Strauss\Test\Latte\RuntimeException;
use Nette;
use function is_array, is_string, count, strlen;

class Filters
{
	public static function checkTagSwitch(string $orig, $new): void
	{
		$new = strtolower($new);
		if ($new === 'style' || $new === 'script' || isset(\Strauss\Test\Latte\Helpers::$emptyElements[strtolower($orig)]) !== isset(\Strauss\Test\Latte\Helpers::$emptyElements[$new])) {
			throw new \Strauss\Test\Latte\RuntimeException("Forbidden tag <{$orig}> change to <{$new}>.");
		}
	}
}
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $namespaceSymbol = new NamespaceSymbol('Latte', $file);
        $namespaceSymbol->setLocalReplacement('Strauss\\Test\\Latte');
        $discoveredSymbols->add($namespaceSymbol);

        $classSymbol = new ClassSymbol('Latte\Runtime\Filters', $file, false, $namespaceSymbol);
        $discoveredSymbols->add($classSymbol);

        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

        $result = $replacer->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertEqualsRemoveBlankLinesLeadingWhitespace($expected, $result);
    }

    public function test_relative_constructor_property(): void
    {

        $contents = <<<'EOD'
<?php

namespace Latte\Tools;

use Latte;
use Nette;

final class Linter
{
	use Latte\Strict;

	public function __construct(?Latte\Engine $engine = null, bool $debug = false)
	{
		$this->engine = $engine;
		$this->debug = $debug;
	}
}
EOD;

        $expected = <<<'EOD'
<?php

namespace Strauss\Test\Latte\Tools;

use Strauss\Test\Latte;
use Nette;

final class Linter
{
	use \Strauss\Test\Latte\Strict;

	public function __construct(?\Strauss\Test\Latte\Engine $engine = null, bool $debug = false)
	{
		$this->engine = $engine;
		$this->debug = $debug;
	}
}
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $namespaceSymbol = new NamespaceSymbol('Latte', $file);
        $namespaceSymbol->setLocalReplacement('Strauss\\Test\\Latte');
        $discoveredSymbols->add($namespaceSymbol);

        $classSymbol = new ClassSymbol('Latte\Tools\Linter', $file, false, $namespaceSymbol);
        $discoveredSymbols->add($classSymbol);

        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

        $result = $replacer->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertEqualsRemoveBlankLinesLeadingWhitespace($expected, $result);
    }

    public function test_relative_exception_type(): void
    {

        $contents = <<<'EOD'
<?php

namespace Latte\Tools;

use Latte;
use Nette;

final class Linter
{
	use Latte\Strict;

	public function lintLatte(string $file): bool
	{
		try {
			$code = $this->engine->compile($s);

		} catch (Latte\CompileException $e) {
			if ($this->debug) {
				echo $e;
			}
			$pos = $e->sourceLine ? ':' . $e->sourceLine : '';
			fwrite(STDERR, "[ERROR]      {$file}{$pos}    {$e->getMessage()}\n");
			return false;

		} finally {
			restore_error_handler();
		}
	}
}
EOD;

        $expected = <<<'EOD'
<?php

namespace Strauss\Test\Latte\Tools;

use Strauss\Test\Latte;
use Nette;

final class Linter
{
	use \Strauss\Test\Latte\Strict;

	public function lintLatte(string $file): bool
	{
		try {
			$code = $this->engine->compile($s);

		} catch (\Strauss\Test\Latte\CompileException $e) {
			if ($this->debug) {
				echo $e;
			}
			$pos = $e->sourceLine ? ':' . $e->sourceLine : '';
			fwrite(STDERR, "[ERROR]      {$file}{$pos}    {$e->getMessage()}\n");
			return false;

		} finally {
			restore_error_handler();
		}
	}
}
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $namespaceSymbol = new NamespaceSymbol('Latte', $file);
        $namespaceSymbol->setLocalReplacement('Strauss\\Test\\Latte');
        $discoveredSymbols->add($namespaceSymbol);

        $classSymbol = new ClassSymbol('Latte\Tools\Linter', $file, false, $namespaceSymbol);
        $discoveredSymbols->add($classSymbol);

        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

        $result = $replacer->replaceInString($discoveredSymbols, $contents, $file);

        $this->assertEqualsRemoveBlankLinesLeadingWhitespace($expected, $result);
    }

    /**
     * @see https://github.com/dompdf/php-font-lib/pull/148
     */
    public function test_namespace_in_string_with_variable(): void
    {

        $contents = <<<'EOD'
<?php

if (!self::$raw) {
  $name_canon = preg_replace("/[^a-z0-9]/", "", strtolower($tag));

  $variableClass = "FontLib\\Table\\Type\\$name_canon";
  $namespace = "FontLib\\Table\\Type";

  if (!isset($this->directory[$tag]) || !@class_exists($class)) {
    return;
  }
}
else {
  $class = "FontLib\\Table\\Type\\Table";
}

$decorator  = "Dompdf\\FrameDecorator\\$decorator";
$unchanged   = "Dompdf\\FrameReflower\\$reflower";
EOD;

        $expected = <<<'EOD'
<?php

if (!self::$raw) {
  $name_canon = preg_replace("/[^a-z0-9]/", "", strtolower($tag));

  $variableClass = "Strauss\\Test\\FontLib\\Table\\Type\\$name_canon";
  $namespace = "Strauss\\Test\\FontLib\\Table\\Type";

  if (!isset($this->directory[$tag]) || !@class_exists($class)) {
    return;
  }
}
else {
  $class = "Strauss\\Test\\FontLib\\Table\\Type\\Table";
}

$decorator  = "Strauss\\Test\\Dompdf\\FrameDecorator\\$decorator";
$unchanged   = "Dompdf\\FrameReflower\\$reflower";
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $originalNamespace = 'FontLib\\Table\\Type';
        $replacement = 'Strauss\\Test\\FontLib\\Table\\Type';

        $secondOriginalNamespace = 'Dompdf\\FrameDecorator';
        $secondReplacement = 'Strauss\\Test\\Dompdf\\FrameDecorator';

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $namespaceSymbol = new NamespaceSymbol($originalNamespace, $file);
        $namespaceSymbol->setLocalReplacement($replacement);

        $classSymbol = new ClassSymbol("FontLib\\Table\\Type\\Table", $file, false, $namespaceSymbol);

        $secondNamespaceSymbol = new NamespaceSymbol($secondOriginalNamespace, $file);
        $secondNamespaceSymbol->setLocalReplacement($secondReplacement);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);
        $discoveredSymbols->add($classSymbol);
        $discoveredSymbols->add($secondNamespaceSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRemoveBlankLinesLeadingWhitespace($expected, $result);
    }

    public function testForAbsenceOfFunctionPrefixInClass(): void
    {
        $contents = <<<'EOD'
<?php

if (! function_exists('my_function')) {
    function my_function()
    {
        return 'global';
    }
}

class MyClass
{
    public function my_function()
    {
        foreach (my_function() as $value) {
        }
        return 'method';
    }
}

$value = my_function();
$value2 = (new MyClass())->my_function();
EOD;

        $expected = <<<'EOD'
<?php

if (! function_exists('myprefix_my_function')) {
    function myprefix_my_function()
    {
        return 'global';
    }
}

class MyClass
{
    public function my_function()
    {
        foreach (myprefix_my_function() as $value) {
        }
        return 'method';
    }
}

$value = myprefix_my_function();
$value2 = (new MyClass())->my_function();
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $symbols = new DiscoveredSymbols();

        $symbol = new FunctionSymbol('my_function', $file);
        $symbol->setLocalReplacement('myprefix_my_function');

        $symbols->add($symbol);

        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());

        $result = $replacer->replaceInString($symbols, $contents, $file);

        $this->assertEqualsRN($expected, $result);
    }

    public function testInclude(): void
    {
        $contents = <<<'EOD'
<?php

namespace Carbon_Fields\Container;

use Carbon_Fields\Helper\Helper;

class User_Meta_Container extends Container {

    public function t() {
        include \Carbon_Fields\DIR . '/f.php';
    }
}
EOD;

        $expected = <<<'EOD'
<?php

namespace Prefix\Strauss\Carbon_Fields\Container;

use Prefix\Strauss\Carbon_Fields\Helper\Helper;

class User_Meta_Container extends Container {

    public function t() {
        include \Prefix\Strauss\Carbon_Fields\DIR . '/f.php';
    }
}
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $originalNamespace = 'Carbon_Fields';
        $replacement = 'Prefix\\Strauss\\Carbon_Fields';

        $filesystem = $this->getInMemoryFileSystem();

        $replacer = new Prefixer($config, $filesystem);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $namespaceSymbol = new NamespaceSymbol($originalNamespace, $file);
        $namespaceSymbol->setLocalReplacement($replacement);
        $discoveredSymbols->add($namespaceSymbol);

        $classSymbol = new ClassSymbol('Prefix\Strauss\Carbon_Fields\Container\User_Meta_Container', $file, false, $namespaceSymbol);
        $discoveredSymbols->add($classSymbol);

        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }

    /**
     * Test for issue #230 - interface name should not be prefixed when it's a relative reference
     * in the same namespace as the implementing class.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/230
     */
    public function testRelativeInterfaceInImplementsNotPrefixed(): void
    {
        $contents = <<<'EOD'
<?php

declare(strict_types=1);

namespace Geocoder;

use Geocoder\Model\Bounds;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;
use Geocoder\Provider\Provider;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class StatefulGeocoder implements Geocoder
{
    /**
     * @var string|null
     */
    private $locale;
}
EOD;

        $expected = <<<'EOD'
<?php

declare(strict_types=1);

namespace CommonsBooking\Geocoder;

use CommonsBooking\Geocoder\Model\Bounds;
use CommonsBooking\Geocoder\Query\GeocodeQuery;
use CommonsBooking\Geocoder\Query\ReverseQuery;
use CommonsBooking\Geocoder\Provider\Provider;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class StatefulGeocoder implements Geocoder
{
    /**
     * @var string|null
     */
    private $locale;
}
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $file = $this->createMock(File::class);
        $file->expects($this->any())->method('addDiscoveredSymbol');
        $file->expects($this->any())->method('getSourcePath');
        $file->expects($this->any())
                 ->method('isDoPrefix')
                 ->willReturn(true);

        $symbols = new DiscoveredSymbols();

        $namespaceSymbol = new NamespaceSymbol('Geocoder', $file);
        $namespaceSymbol->setLocalReplacement('CommonsBooking\\Geocoder');
        $symbols->add($namespaceSymbol);

        $classSymbol = new ClassSymbol('Geocoder\StatefulGeocoder', $file, false, $namespaceSymbol);
        $symbols->add($classSymbol);

        $replacer = new Prefixer($config, $this->getInMemoryFileSystem());
        $result = $replacer->replaceInString($symbols, $contents, $file);

        $this->assertEqualsRN($expected, $result);
    }

    public function test_return_type_classname_not_replaced_as_namespace(): void
    {

        $contents = <<<'EOD'
namespace Composer;

class Factory
{
    public static function create(IOInterface $io, $config = null, $disablePlugins = false, bool $disableScripts = false): Composer
    {}
}
EOD;

        // public static function create(IOInterface $io, $config =1 null, $disablePlugins = false, bool $disableScripts = false): BrianHenryIE\Strauss\Vendor\Composer
        $expected = <<<'EOD'
namespace BrianHenryIE\Strauss\Vendor\Composer;

class Factory
{
    public static function create(IOInterface $io, $config = null, $disablePlugins = false, bool $disableScripts = false): Composer
    {}
}
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $namespaceSymbol = new NamespaceSymbol('Composer', $file);
        $namespaceSymbol->setLocalReplacement('BrianHenryIE\\Strauss\\Vendor\\Composer');
        $discoveredSymbols->add($namespaceSymbol);

        $classSymbol = new ClassSymbol('Composer\\Factory', $file, false, $namespaceSymbol);
        $discoveredSymbols->add($classSymbol);

        $filesystem = $this->getInMemoryFileSystem();
        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer = new Prefixer($config, $filesystem);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }

    /**
     * @covers ::prepareRelativeNamespaces()
     */
    public function test_use_trait_fqdn(): void
    {

        $contents = <<<'EOD'
<?php

namespace Stripe\Billing;

class CreditGrant extends \Stripe\ApiResource
{
    const OBJECT_NAME = 'billing.credit_grant';

    use \Stripe\ApiOperations\Update;

    const CATEGORY_PAID = 'paid';
    const CATEGORY_PROMOTIONAL = 'promotional';
}
EOD;

        $expected = <<<'EOD'
<?php

namespace Stripe\Billing;

class CreditGrant extends \Stripe\ApiResource
{
    const OBJECT_NAME = 'billing.credit_grant';

    use \BrianHenryIE\Strauss\Stripe\ApiOperations\Update;

    const CATEGORY_PAID = 'paid';
    const CATEGORY_PROMOTIONAL = 'promotional';
}
EOD;

        $config = $this->createMock(PrefixerConfigInterface::class);

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $discoveredSymbols = new DiscoveredSymbols();

        $namespaceSymbol = new NamespaceSymbol('Stripe\\ApiOperations', $file);
        $namespaceSymbol->setLocalReplacement('BrianHenryIE\\Strauss\\Stripe\\ApiOperations');
        $discoveredSymbols->add($namespaceSymbol);

        $traitSymbol = new TraitSymbol('Stripe\\ApiOperations\\Update', $file, $namespaceSymbol);
        $discoveredSymbols->add($traitSymbol);

        $filesystem = $this->getInMemoryFileSystem();
        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer = new Prefixer($config, $filesystem);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }

    public function test_namespace_of_file_excluded(): void
    {
        $contents = <<<'EOD'
<?php
namespace Composer\Autoload;

class Classloader {}
EOD;

        $expected = <<<'EOD'
<?php
namespace Composer\Autoload;

class Classloader {}
EOD;

        $config = Mockery::mock(PrefixerConfigInterface::class);
//        $config->expects('getClassmapPrefix')->andReturn('Prefix_');
        $config->expects('isTargetDirectoryVendor')->andReturnFalse();
        $config->expects('getConstantsPrefix')->andReturn('Prefix_')->zeroOrMoreTimes();

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );
        $file->setDoPrefix(false);

        $discoveredSymbols = new DiscoveredSymbols();

        $namespaceSymbol = new NamespaceSymbol('Composer\Autoload', $file);
        $namespaceSymbol->setLocalReplacement('MyProject\Dependencies\Composer\Autoload');
        $discoveredSymbols->add($namespaceSymbol);

        $classSymbol = new ClassSymbol('ClassLoader', $file);
        $classSymbol->setDoRename(false);
        $discoveredSymbols->add($classSymbol);

        $filesystem = $this->getInMemoryFileSystem();
        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer = new Prefixer($config, $filesystem);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }

    public function test_use_class_as_alias(): void
    {
        $contents = <<<'EOD'
<?php
namespace BrianHenryIE\Strauss\Console;

use Symfony\Component\Console\Application as BaseApplication;

class Application extends BaseApplication
{
}
EOD;

        $expected = <<<'EOD'
<?php
namespace BrianHenryIE\Strauss\Console;

use BrianHenryIE\Strauss\Vendor\Symfony\Component\Console\Application as BaseApplication;

class Application extends BaseApplication
{
}
EOD;

        $config = Mockery::mock(PrefixerConfigInterface::class);
//        $config->expects('getClassmapPrefix')->andReturn('Prefix_');
        $config->expects('isTargetDirectoryVendor')->andReturnFalse();
        $config->expects('getConstantsPrefix')->andReturn('Prefix_')->zeroOrMoreTimes();

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );
        $file->setDoPrefix(false);

        $discoveredSymbols = new DiscoveredSymbols();

        $namespaceSymbol = new NamespaceSymbol('Symfony\Component\Console\Application', $file);
        $namespaceSymbol->setLocalReplacement('BrianHenryIE\Strauss\Vendor\Symfony\Component\Console');
        $discoveredSymbols->add($namespaceSymbol);

        $classSymbol = new ClassSymbol('Symfony\Component\Console\Application\Application', $file, false, $namespaceSymbol);
        $classSymbol->setDoRename(false);
        $discoveredSymbols->add($classSymbol);

        $filesystem = $this->getInMemoryFileSystem();
        $filesystem->write($file->getTargetAbsolutePath(), $contents);

        $replacer = new Prefixer($config, $filesystem);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }



    // Commenting out test that is not failing as required.
//    public function test_global_class(): void
//    {
//        $contents = <<<'EOD'
//<?php
//namespace WPGraphQL\Registry\Utils;
//
//use WPGraphQL;
//EOD;
//
//        // `use \Prefix_WPGraphQL;`.
//        $expected = <<<'EOD'
//<?php
//namespace MyProject\Dependencies\WPGraphQL\Registry\Utils;
//
//use Prefix_WPGraphQL as WPGraphQL;
//EOD;
//
//        $config = Mockery::mock(PrefixerConfigInterface::class);
//        $config->expects('getClassmapPrefix')->andReturn('Prefix_');
//        $config->expects('isTargetDirectoryVendor')->andReturnFalse();
//        $config->expects('getConstantsPrefix')->andReturn('Prefix_');
//
//        $file = new File(
//            'vendor/package/name/src/file.php',
//            'package/name/src/file.php',
//            'vendor-prefixed/package/name/src/file.php',
//        );
//
//        $discoveredSymbols = new DiscoveredSymbols();
//
//        $namespaceSymbol = new NamespaceSymbol('WPGraphQL\Registry', $file);
//        $namespaceSymbol->setLocalReplacement('MyProject\Dependencies\WPGraphQL\Registry');
//        $discoveredSymbols->add($namespaceSymbol);
//
//        $namespaceSymbol = new NamespaceSymbol('WPGraphQL', $file);
//        $namespaceSymbol->setLocalReplacement('MyProject\Dependencies\WPGraphQL');
//        $discoveredSymbols->add($namespaceSymbol);
//
//        $classSymbol = new ClassSymbol('WPGraphQL', $file);
//        $classSymbol->setLocalReplacement('Prefix_WPGraphQL');
//        $discoveredSymbols->add($classSymbol);
//
//        $filesystem = $this->getInMemoryFileSystem();
//        $filesystem->write($file->getTargetAbsolutePath(), $contents);
//
//        $replacer = new Prefixer($config, $filesystem);
//
//        $replacer->replaceInFiles($discoveredSymbols, [$file]);
//
//        $result = $filesystem->read($file->getTargetAbsolutePath());
//
//        $this->assertEqualsRN($expected, $result);
//    }
}
