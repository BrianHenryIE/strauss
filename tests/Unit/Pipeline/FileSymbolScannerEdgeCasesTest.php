<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Config\FileSymbolScannerConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\EnumSymbol;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Snapshot tests of everything {@see FileSymbolScanner} discovers in a file, covering PHP syntax edge cases.
 *
 * @covers \BrianHenryIE\Strauss\Pipeline\FileSymbolScanner
 */
class FileSymbolScannerEdgeCasesTest extends TestCase
{
    /**
     * @dataProvider fixtureProvider
     *
     * @param array<string,mixed> $expected
     */
    #[DataProvider('fixtureProvider')]
    public function test_discovered_symbols(string $contents, array $expected): void
    {
        $this->assertSame($expected, $this->snapshotSymbols($this->scan($contents)));
    }

    /**
     * @return array<string,array{0:string,1:array<string,mixed>}>
     */
    public static function fixtureProvider(): array
    {
        return [
            'single namespace' => [
                <<<'PHP'
<?php
namespace Example\Pkg;

interface ParentContract {}
interface ChildContract extends ParentContract {}

abstract class BaseService implements ChildContract {}
class ConcreteService extends BaseService {}

trait LocalTrait {}

function helper_fn() {
    return true;
}

const LOCAL_CONST = 123;
PHP,
                [
                    'namespaces' => ['Example\Pkg'],
                    'classes' => [
                        'Example\Pkg\BaseService' => ['abstract' => true, 'extends' => null, 'interfaces' => ['Example\Pkg\ChildContract']],
                        'Example\Pkg\ConcreteService' => ['abstract' => false, 'extends' => 'Example\Pkg\BaseService', 'interfaces' => []],
                    ],
                    'functions' => ['Example\Pkg\helper_fn'],
                    'constants' => ['Example\Pkg\LOCAL_CONST'],
                    'interfaces' => ['Example\Pkg\ParentContract', 'Example\Pkg\ChildContract'],
                    'traits' => ['Example\Pkg\LocalTrait'],
                    'enums' => [],
                ],
            ],
            'multiple namespaces including explicit global' => [
                <<<'PHP'
<?php
namespace One\Ns {
    interface InterfaceA {}
    class ClassA implements InterfaceA {}
}

namespace {
    class GlobalClass {}
    function global_helper() {
        return true;
    }
    const GLOBAL_CONST = 1;
}

namespace Two\Ns {
    trait TraitB {}
    class ClassB {}
}
PHP,
                [
                    'namespaces' => ['One\Ns', '\\', 'Two\Ns'],
                    'classes' => [
                        'One\Ns\ClassA' => ['abstract' => false, 'extends' => null, 'interfaces' => ['One\Ns\InterfaceA']],
                        'GlobalClass' => ['abstract' => false, 'extends' => null, 'interfaces' => []],
                        'Two\Ns\ClassB' => ['abstract' => false, 'extends' => null, 'interfaces' => []],
                    ],
                    'functions' => ['global_helper'],
                    'constants' => ['GLOBAL_CONST'],
                    'interfaces' => ['One\Ns\InterfaceA'],
                    'traits' => ['Two\Ns\TraitB'],
                    'enums' => [],
                ],
            ],
            'namespace keyword in string and namespace operator' => [
                <<<'PHP'
<?php
namespace Edge\One;
class Subject {}
function inspect_namespace_operator() {
    $txt = "namespace Edge\\Fake should not count";
    return namespace\Subject::class . $txt;
}
PHP,
                [
                    'namespaces' => ['Edge\One'],
                    'classes' => [
                        'Edge\One\Subject' => ['abstract' => false, 'extends' => null, 'interfaces' => []],
                    ],
                    'functions' => ['Edge\One\inspect_namespace_operator'],
                    'constants' => [],
                    'interfaces' => [],
                    'traits' => [],
                    'enums' => [],
                ],
            ],
            'explicit global namespace with define, const and class constant' => [
                <<<'PHP'
<?php
namespace {
    define('EDGE_DEFINED', 'ok');
    const EDGE_CONST = 'c';
    class GlobalEdge {
        public const LOCAL = 'x';
    }
}
PHP,
                [
                    'namespaces' => ['\\'],
                    'classes' => [
                        'GlobalEdge' => ['abstract' => false, 'extends' => null, 'interfaces' => []],
                    ],
                    'functions' => [],
                    'constants' => ['EDGE_DEFINED', 'EDGE_CONST'],
                    'interfaces' => [],
                    'traits' => [],
                    'enums' => [],
                ],
            ],
            'interface inheritance chain and implementation' => [
                <<<'PHP'
<?php
namespace Edge\Two;
interface A {}
interface B extends A {}
interface C extends B {}
abstract class Core implements C {}
class Child extends Core {}
PHP,
                [
                    'namespaces' => ['Edge\Two'],
                    'classes' => [
                        'Edge\Two\Core' => ['abstract' => true, 'extends' => null, 'interfaces' => ['Edge\Two\C']],
                        'Edge\Two\Child' => ['abstract' => false, 'extends' => 'Edge\Two\Core', 'interfaces' => []],
                    ],
                    'functions' => [],
                    'constants' => [],
                    'interfaces' => ['Edge\Two\A', 'Edge\Two\B', 'Edge\Two\C'],
                    'traits' => [],
                    'enums' => [],
                ],
            ],
            'extends and implements are resolved through use statement aliases' => [
                <<<'PHP'
<?php
namespace Edge\Three;
use Vendor\Package\ExternalClass as ExternalAlias;
class LocalOne extends ExternalAlias implements \Countable, LocalInterface {}
class LocalTwo extends LocalOne {}
function takes_alias(ExternalAlias $a): string {
    return LocalTwo::class;
}
PHP,
                [
                    'namespaces' => ['Edge\Three'],
                    'classes' => [
                        'Edge\Three\LocalOne' => ['abstract' => false, 'extends' => 'Vendor\Package\ExternalClass', 'interfaces' => ['Countable', 'Edge\Three\LocalInterface']],
                        'Edge\Three\LocalTwo' => ['abstract' => false, 'extends' => 'Edge\Three\LocalOne', 'interfaces' => []],
                    ],
                    'functions' => ['Edge\Three\takes_alias'],
                    'constants' => [],
                    'interfaces' => [],
                    'traits' => [],
                    'enums' => [],
                ],
            ],
            'anonymous class is ignored' => [
                <<<'PHP'
<?php
namespace Edge\Four;
$v = new class () {
    public function x(): string { return 'y'; }
};
class NamedAfterAnonymous {}
PHP,
                [
                    'namespaces' => ['Edge\Four'],
                    'classes' => [
                        'Edge\Four\NamedAfterAnonymous' => ['abstract' => false, 'extends' => null, 'interfaces' => []],
                    ],
                    'functions' => [],
                    'constants' => [],
                    'interfaces' => [],
                    'traits' => [],
                    'enums' => [],
                ],
            ],
            'traits in namespace blocks' => [
                <<<'PHP'
<?php
namespace Edge\Five\A {
    trait SharedA {}
    class UseA {
        use SharedA;
    }
}
namespace Edge\Five\B {
    trait SharedB {}
    class UseB {
        use SharedB;
    }
}
PHP,
                [
                    'namespaces' => ['Edge\Five\A', 'Edge\Five\B'],
                    'classes' => [
                        'Edge\Five\A\UseA' => ['abstract' => false, 'extends' => null, 'interfaces' => []],
                        'Edge\Five\B\UseB' => ['abstract' => false, 'extends' => null, 'interfaces' => []],
                    ],
                    'functions' => [],
                    'constants' => [],
                    'interfaces' => [],
                    'traits' => ['Edge\Five\A\SharedA', 'Edge\Five\B\SharedB'],
                    'enums' => [],
                ],
            ],
            'attributes, union types and match' => [
                <<<'PHP'
<?php
namespace Edge\Six;
#[\Attribute]
class Marker {}
#[Marker]
class Advanced {
    public function run(int|string $x): string {
        return match (true) {
            is_int($x) => 'int',
            default => 'string',
        };
    }
}
PHP,
                [
                    'namespaces' => ['Edge\Six'],
                    'classes' => [
                        'Edge\Six\Marker' => ['abstract' => false, 'extends' => null, 'interfaces' => []],
                        'Edge\Six\Advanced' => ['abstract' => false, 'extends' => null, 'interfaces' => []],
                    ],
                    'functions' => [],
                    'constants' => [],
                    'interfaces' => [],
                    'traits' => [],
                    'enums' => [],
                ],
            ],
            'backed enum implementing interface and readonly class' => [
                <<<'PHP'
<?php
namespace Edge\Seven;
interface HasLabel {}
enum Status: string implements HasLabel {
    const DEFAULT_REGEX = '/^(ready|done)$/';
    case READY = 'ready';
}
readonly class Holder {
    public function __construct(public string $id) {}
}
PHP,
                [
                    'namespaces' => ['Edge\Seven'],
                    'classes' => [
                        'Edge\Seven\Holder' => ['abstract' => false, 'extends' => null, 'interfaces' => []],
                    ],
                    'functions' => [],
                    'constants' => [],
                    'interfaces' => ['Edge\Seven\HasLabel'],
                    'traits' => [],
                    'enums' => [
                        'Edge\Seven\Status' => ['backingType' => 'string', 'interfaces' => ['Edge\Seven\HasLabel']],
                    ],
                ],
            ],
            'global pure enum' => [
                <<<'PHP'
<?php
enum GlobalSuit {
    case Hearts;
    case Spades;
}
PHP,
                [
                    'namespaces' => ['\\'],
                    'classes' => [],
                    'functions' => [],
                    'constants' => [],
                    'interfaces' => [],
                    'traits' => [],
                    'enums' => [
                        'GlobalSuit' => ['backingType' => null, 'interfaces' => []],
                    ],
                ],
            ],
            'conditional declarations, nested declarations and define variants' => [
                <<<'PHP'
<?php
namespace Foo;
if (!function_exists('Foo\bar')) {
    function bar() {}
}
if (!class_exists('Foo\Baz')) {
    class Baz extends \Exception implements \Countable, Qux {}
}
define('IN_NS', 1);
\define('IN_NS2', 1);
define('Foo\IN_NS3', 1);
define($dynamic, 1);
const A = 1, B = 2;
function inner() {
    class InFunc {}
}
PHP,
                [
                    'namespaces' => ['Foo'],
                    'classes' => [
                        'Foo\Baz' => ['abstract' => false, 'extends' => 'Exception', 'interfaces' => ['Countable', 'Foo\Qux']],
                        'Foo\InFunc' => ['abstract' => false, 'extends' => null, 'interfaces' => []],
                    ],
                    'functions' => ['Foo\bar', 'Foo\inner'],
                    'constants' => ['Foo\IN_NS', 'Foo\IN_NS2', 'Foo\IN_NS3', 'Foo\A', 'Foo\B'],
                    'interfaces' => [],
                    'traits' => [],
                    'enums' => [],
                ],
            ],
            'declare and comments before namespace' => [
                <<<'PHP'
<?php
/**
 * File comment mentioning namespace Fake\Comment;
 */
declare(strict_types=1);
namespace DeclaredDemo;
function declared_helper() {}
PHP,
                [
                    'namespaces' => ['DeclaredDemo'],
                    'classes' => [],
                    'functions' => ['DeclaredDemo\declared_helper'],
                    'constants' => [],
                    'interfaces' => [],
                    'traits' => [],
                    'enums' => [],
                ],
            ],
            'php inside html' => [
                '<html><?php class InHtml {} ?></html>',
                [
                    'namespaces' => ['\\'],
                    'classes' => [
                        'InHtml' => ['abstract' => false, 'extends' => null, 'interfaces' => []],
                    ],
                    'functions' => [],
                    'constants' => [],
                    'interfaces' => [],
                    'traits' => [],
                    'enums' => [],
                ],
            ],
            'no php open tag' => [
                'class NoOpenTag {}',
                [
                    'namespaces' => ['\\'],
                    'classes' => [
                        'NoOpenTag' => ['abstract' => false, 'extends' => null, 'interfaces' => []],
                    ],
                    'functions' => [],
                    'constants' => [],
                    'interfaces' => [],
                    'traits' => [],
                    'enums' => [],
                ],
            ],
            'template file with placeholders is a parse error and discovers nothing' => [
                <<<'PHP'
<?php
namespace %g_namespace%\AdminMenus;
class AdminMenuExample {}
PHP,
                [
                    'namespaces' => [],
                    'classes' => [],
                    'functions' => [],
                    'constants' => [],
                    'interfaces' => [],
                    'traits' => [],
                    'enums' => [],
                ],
            ],
        ];
    }

    public function test_large_real_world_file(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/Issues/data/Mpdf.php');
        self::assertIsString($contents);

        $symbols = $this->scan($contents);

        $this->assertSame(['Mpdf'], array_keys($symbols->getNamespaces()->toArray()));
        $class = $symbols->getClass('Mpdf\Mpdf');
        $this->assertInstanceOf(ClassSymbol::class, $class);
        $this->assertSame(['Psr\Log\LoggerAwareInterface'], $class->getInterfaces());
    }

    private function scan(string $contents): DiscoveredSymbols
    {
        $config = $this->createMock(FileSymbolScannerConfigInterface::class);
        $config->method('getProjectAbsolutePath')->willReturn('project');
        $config->method('getPackagesToPrefix')->willReturn([]);

        $filesystem = Mockery::mock(FileSystem::class);
        $filesystem->expects('read')->once()->andReturn($contents);
        $filesystem->expects('getRelativePath')->once()->andReturnArg(1);

        $file = new File('/tmp/fixture.php', 'fixture.php', '/tmp/fixture.php');

        $sut = new FileSymbolScanner($config, new DiscoveredSymbols(), $filesystem);

        return $sut->findInFiles(new DiscoveredFiles([$file]));
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshotSymbols(DiscoveredSymbols $symbols): array
    {
        $classes = [];
        foreach ($symbols->getAllClasses() as $name => $classSymbol) {
            assert($classSymbol instanceof ClassSymbol);
            $classes[$name] = [
                'abstract' => $classSymbol->isAbstract(),
                'extends' => $classSymbol->getExtends(),
                'interfaces' => $classSymbol->getInterfaces(),
            ];
        }

        $enums = [];
        foreach ($symbols->getDiscoveredEnums() as $name => $enumSymbol) {
            assert($enumSymbol instanceof EnumSymbol);
            $enums[$name] = [
                'backingType' => $enumSymbol->getBackingType(),
                'interfaces' => $enumSymbol->getInterfaces(),
            ];
        }

        return [
            'namespaces' => array_keys($symbols->getNamespaces()->toArray()),
            'classes' => $classes,
            'functions' => array_keys($symbols->getDiscoveredFunctions()->toArray()),
            'constants' => array_keys($symbols->getConstants()->toArray()),
            'interfaces' => array_keys($symbols->getDiscoveredInterfaces()->toArray()),
            'traits' => array_keys($symbols->getDiscoveredTraits()->toArray()),
            'enums' => $enums,
        ];
    }
}
