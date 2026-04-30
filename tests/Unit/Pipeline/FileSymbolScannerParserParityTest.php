<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\SimplePhpParser\Parsers\Helper\ParserContainer;
use BrianHenryIE\SimplePhpParser\Parsers\PhpCodeParser;
use BrianHenryIE\Strauss\Config\FileSymbolScannerConfigInterface;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\InterfaceSymbol;
use BrianHenryIE\Strauss\Types\TraitSymbol;
use Mockery;
use PhpParser\Error;
use PhpParser\ParserFactory;

/**
 * @covers \BrianHenryIE\Strauss\Pipeline\FileSymbolScanner
 */
class FileSymbolScannerParserParityTest extends TestCase
{
    public function test_process_parser_matches_legacy_for_single_namespace_symbol_data(): void
    {
        $contents = <<<'PHP'
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
PHP;

        $this->assertScannerParity($contents, '/tmp/single-namespace.php');
    }

    public function test_process_parser_matches_legacy_for_multi_namespace_symbol_data(): void
    {
        $contents = <<<'PHP'
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
PHP;

        $this->assertScannerParity($contents, '/tmp/multi-namespace.php');
    }

    public function test_process_parser_matches_legacy_for_large_real_world_fixture(): void
    {
        $fixturePath = dirname(__DIR__, 2) . '/Issues/data/Mpdf.php';
        $contents = file_get_contents($fixturePath);
        self::assertIsString($contents);

        $this->assertScannerParity($contents, '/tmp/mpdf.php');
    }

    /**
     * @dataProvider edgeCaseProvider
     */
    public function test_process_parser_matches_legacy_for_edge_cases(string $contents, string $sourcePath): void
    {
        $this->assertScannerParity($contents, $sourcePath);
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function edgeCaseProvider(): array
    {
        return [
            'plain_global_file' => [
                <<<'PHP'
<?php
function plain_global_helper() {
    return true;
}
PHP,
                '/tmp/plain-global.php',
            ],
            'namespace_keyword_in_string_and_namespace_operator' => [
                <<<'PHP'
<?php
namespace Edge\One;
class Subject {}
function inspect_namespace_operator() {
    $txt = "namespace Edge\\Fake should not count";
    return namespace\Subject::class . $txt;
}
PHP,
                '/tmp/edge-ns-operator.php',
            ],
            'explicit_global_namespace_with_define_and_consts' => [
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
                '/tmp/edge-global.php',
            ],
            'interface_inheritance_chain_and_implementation' => [
                <<<'PHP'
<?php
namespace Edge\Two;
interface A {}
interface B extends A {}
interface C extends B {}
abstract class Core implements C {}
class Child extends Core {}
PHP,
                '/tmp/edge-interfaces.php',
            ],
            'imports_aliases_and_use_statement_noise' => [
                <<<'PHP'
<?php
namespace Edge\Three;
use Vendor\Package\ExternalClass as ExternalAlias;
class LocalOne {}
class LocalTwo extends LocalOne {}
function takes_alias(ExternalAlias $a): string {
    return LocalTwo::class;
}
PHP,
                '/tmp/edge-use-alias.php',
            ],
            'anonymous_class_and_named_class' => [
                <<<'PHP'
<?php
namespace Edge\Four;
$v = new class () {
    public function x(): string { return 'y'; }
};
class NamedAfterAnonymous {}
PHP,
                '/tmp/edge-anon.php',
            ],
            'traits_and_nested_namespace_blocks' => [
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
                '/tmp/edge-traits.php',
            ],
            'modern_types_attributes_and_match' => [
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
                '/tmp/edge-modern.php',
            ],
            'enum_and_readonly_class_presence' => [
                <<<'PHP'
<?php
namespace Edge\Seven;
enum Status: string {
    case READY = 'ready';
}
readonly class Holder {
    public function __construct(public string $id) {}
}
PHP,
                '/tmp/edge-enum-readonly.php',
            ],
        ];
    }

    private function countOpeningTags(string $contents): int
    {
        $count = 0;
        foreach (token_get_all($contents) as $token) {
            if (is_array($token) && $token[0] === T_OPEN_TAG) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param string[] $parserInputs
     */
    private function assertParserInputsAreValid(array $parserInputs): void
    {
        self::assertNotEmpty($parserInputs);

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        foreach ($parserInputs as $parserInput) {
            self::assertSame(
                1,
                $this->countOpeningTags($parserInput),
                'Scanner namespace split should produce exactly one PHP opening tag per parser input.'
            );

            try {
                $parser->parse($parserInput);
            } catch (Error $e) {
                self::fail('Scanner namespace split produced invalid PHP: ' . $e->getMessage());
            }
        }
    }

    private function assertScannerParity(string $contents, string $sourcePath): void
    {
        $legacyScanner = $this->newScanner(LegacyGetFromStringScanner::class);
        /** @var RecordingParserInputScanner $modernScanner */
        $modernScanner = $this->newScanner(RecordingParserInputScanner::class);

        $legacySymbols = $legacyScanner->scanString($contents, new File($sourcePath, basename($sourcePath)));
        $modernSymbols = $modernScanner->scanString($contents, new File($sourcePath . '.new', basename($sourcePath . '.new')));
        $this->assertParserInputsAreValid($modernScanner->getParserInputs());

        self::assertSame(
            $this->snapshotSymbols($legacySymbols),
            $this->snapshotSymbols($modernSymbols)
        );
    }

    /**
     * @param class-string<ScannerHarness> $scannerClass
     */
    private function newScanner(string $scannerClass): ScannerHarness
    {
        $config = $this->createMock(FileSymbolScannerConfigInterface::class);
        $config->method('getProjectDirectory')->willReturn('/project');
        $config->method('getPackagesToPrefix')->willReturn([]);

        /** @var FileSystem&\Mockery\MockInterface $filesystem */
        $filesystem = Mockery::mock(FileSystem::class);

        return new $scannerClass(
            $config,
            new DiscoveredSymbols(),
            $filesystem,
            $this->getLogger()
        );
    }

    /**
     * @return array{
     *     namespaces: array<int,string>,
     *     classes: array<string,array{namespace:?string,abstract:bool,extends:?string,interfaces:array<int,string>}>,
     *     functions: array<int,string>,
     *     constants: array<int,string>,
     *     interfaces: array<string,array{namespace:?string,extends:array<int,string>}>,
     *     traits: array<string,array{namespace:?string,uses:array<int,string>}>
     * }
     */
    private function snapshotSymbols(DiscoveredSymbols $symbols): array
    {
        $namespaces = array_keys($symbols->getNamespaces());
        sort($namespaces);

        $classes = [];
        foreach ($symbols->getAllClasses() as $name => $classSymbol) {
            assert($classSymbol instanceof ClassSymbol);
            $interfaces = $classSymbol->getInterfaces();
            sort($interfaces);

            $classes[$name] = [
                'namespace' => $classSymbol->getNamespace(),
                'abstract' => $classSymbol->isAbstract(),
                'extends' => $classSymbol->getExtends(),
                'interfaces' => $interfaces,
            ];
        }
        ksort($classes);

        $functions = array_keys($symbols->getDiscoveredFunctions());
        sort($functions);

        $constants = array_keys($symbols->getConstants());
        sort($constants);

        $interfaces = [];
        foreach ($symbols->getDiscoveredInterfaces() as $name => $interfaceSymbol) {
            assert($interfaceSymbol instanceof InterfaceSymbol);
            $extends = $interfaceSymbol->getExtends();
            sort($extends);
            $interfaces[$name] = [
                'namespace' => $interfaceSymbol->getNamespace(),
                'extends' => $extends,
            ];
        }
        ksort($interfaces);

        $traits = [];
        foreach ($symbols->getDiscoveredTraits() as $name => $traitSymbol) {
            assert($traitSymbol instanceof TraitSymbol);
            $uses = $traitSymbol->getUses();
            sort($uses);
            $traits[$name] = [
                'namespace' => $traitSymbol->getNamespace(),
                'uses' => $uses,
            ];
        }
        ksort($traits);

        return [
            'namespaces' => $namespaces,
            'classes' => $classes,
            'functions' => $functions,
            'constants' => $constants,
            'interfaces' => $interfaces,
            'traits' => $traits,
        ];
    }
}

class ScannerHarness extends FileSymbolScanner
{
    public function scanString(string $contents, FileBase $file): DiscoveredSymbols
    {
        $this->find($contents, $file, null);

        return $this->discoveredSymbols;
    }
}

final class LegacyGetFromStringScanner extends ScannerHarness
{
    protected function parsePhpCode(string $contents): ParserContainer
    {
        return PhpCodeParser::getFromString($contents);
    }
}

final class RecordingParserInputScanner extends ScannerHarness
{
    /** @var string[] */
    private array $parserInputs = [];

    /**
     * @return string[]
     */
    public function getParserInputs(): array
    {
        return $this->parserInputs;
    }

    protected function parsePhpCode(string $contents): ParserContainer
    {
        $this->parserInputs[] = $contents;

        return parent::parsePhpCode($contents);
    }
}
