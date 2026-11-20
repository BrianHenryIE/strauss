<?php

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\PhpFileAnalyzer;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\GlobalsNamespacer
 */
class GlobalsNamespacerTest extends TestCase
{
    /**
     * @return array<string, array{0:string, 1:string}> Global name, expected replacement.
     */
    public static function replacementDataProvider(): array
    {
        return [
            'no prefix' => ['Parsedown', 'Company\\Project\\Parsedown'],
            'prefix stripped, segments become namespaces' => ['HTMLPurifier_AttrDef_CSS_Color', 'Company\\Project\\AttrDef\\CSS\\Color'],
            'class named as the prefix' => ['HTMLPurifier', 'Company\\Project\\HTMLPurifier'],
            'longer prefix tried first' => ['HTMLPurifierX_Foo', 'Company\\Project\\Foo'],
            'other names keep their underscores' => ['Other_Name', 'Company\\Project\\Other_Name'],
            'reserved short name, prefixed with the segment before it' => ['HTMLPurifier_HTMLModule_List', 'Company\\Project\\HTMLModule\\HTMLModuleList'],
            'reserved suffix' => ['HTMLPurifier_Extension_Abstract', 'Company\\Project\\Extension\\ExtensionAbstract'],
            'reserved suffix, deeper' => ['HTMLPurifier_REST_Args_Trait', 'Company\\Project\\REST\\Args\\ArgsTrait'],
            'reserved short name with no segment before it, prefixed with the library name' => ['HTMLPurifier_List', 'Company\\Project\\HTMLPurifierList'],
            'reserved name without a prefix, prefixed with the last namespace segment' => ['Empty', 'Company\\Project\\ProjectEmpty'],
        ];
    }

    /**
     * @covers ::__construct
     * @covers ::getReplacement
     * @dataProvider \BrianHenryIE\Strauss\Pipeline\GlobalsNamespacerTest::replacementDataProvider
     *
     * @param string $globalName The global class name.
     * @param string $expected   The namespaced name.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('replacementDataProvider')]
    public function test_get_replacement(string $globalName, string $expected): void
    {
        $sut = new GlobalsNamespacer('\\Company\\Project\\', ['HTMLPurifier_', 'HTMLPurifierX_'], ['/project'], new NullLogger());

        $this->assertSame($expected, $sut->getReplacement($globalName));
    }

    /**
     * @param string $path     The file's absolute path.
     * @param string $contents Its PHP.
     */
    protected function getFile(string $path, string $contents): File
    {
        $file = new File($path, ltrim($path, '/'), $path);
        $file->setCodeMap((new PhpFileAnalyzer())->analyze($contents));
        return $file;
    }

    /**
     * @covers ::determineReplacements
     * @covers ::canFileMove
     * @covers ::isInPaths
     * @covers ::isClassLike
     */
    public function test_determine_replacements(): void
    {
        $globalNamespace = new NamespaceSymbol('\\', $this->getFile('/project/src/Moved.php', "<?php\nclass Acme_Moved {}\n"));

        $symbols = new DiscoveredSymbols([$globalNamespace]);

        $add = function (string $class, File $file) use ($globalNamespace, $symbols): ClassSymbol {
            $symbol = new ClassSymbol($class, $file, $globalNamespace);
            $symbol->setDoRename(true);
            $symbols->add($symbol);
            return $symbol;
        };

        $moved = $add('Acme_Moved', $this->getFile('/project/src/Moved.php', "<?php\nclass Acme_Moved {}\n"));
        $outside = $add('Acme_Outside', $this->getFile('/project/other/Outside.php', "<?php\nclass Acme_Outside {}\n"));
        $withFunctionFile = $this->getFile('/project/src/WithFunction.php', "<?php\nfunction acme_f() {}\nclass Acme_WithFunction {}\n");
        $withFunction = $add('Acme_WithFunction', $withFunctionFile);
        $withConstFile = $this->getFile('/project/src/WithConst.php', "<?php\nconst ACME_C = 1;\nclass Acme_WithConst {}\n");
        $withConst = $add('Acme_WithConst', $withConstFile);
        $withDefineFile = $this->getFile('/project/src/WithDefine.php', "<?php\ndefine('ACME_X', 1);\nclass Acme_WithDefine {}\n");
        $withDefine = $add('Acme_WithDefine', $withDefineFile);

        $constConstant = new ConstantSymbol('ACME_C', $withConstFile, $globalNamespace);
        $symbols->add($constConstant);
        $defineConstant = new ConstantSymbol('ACME_X', $withDefineFile, $globalNamespace);
        $defineConstant->setDoRename(true);
        $symbols->add($defineConstant);
        $mixed = $add('Acme_Mixed', $this->getFile('/project/src/Mixed.php', "<?php\nnamespace Other { class Thing {} }\nnamespace { class Acme_Mixed {} }\n"));
        $bracketed = $add('Acme_Bracketed', $this->getFile('/project/src/Bracketed.php', "<?php\nnamespace { class Acme_Bracketed {} }\n"));
        $twoBlocks = $add('Acme_TwoBlocks', $this->getFile('/project/src/TwoBlocks.php', "<?php\nnamespace { class Acme_TwoBlocks {} }\nnamespace { echo 1; }\n"));
        $reserved = $add('Acme_List', $this->getFile('/project/src/List.php', "<?php\nclass Acme_List {}\n"));

        $twoNamespacesFile = $this->getFile('/project/src/Two.php', "<?php\nclass Acme_One {}\nclass Acme_Sub_Two {}\n");
        $one = $add('Acme_One', $twoNamespacesFile);
        $two = $add('Acme_Sub_Two', $twoNamespacesFile);

        $function = new FunctionSymbol('acme_f', $withFunctionFile, $globalNamespace);
        $function->setDoRename(true);
        $symbols->add($function);

        $functionsOnly = new FunctionSymbol('acme_g', $this->getFile('/project/src/functions.php', "<?php\nfunction acme_g() {}\n"), $globalNamespace);
        $symbols->add($functionsOnly);

        $outsideFunction = new FunctionSymbol('acme_h', $this->getFile('/project/other/functions.php', "<?php\nfunction acme_h() {}\n"), $globalNamespace);
        $outsideFunction->setDoRename(true);
        $symbols->add($outsideFunction);

        (new GlobalsNamespacer('Company\\Project', ['Acme_'], ['/project/src'], new NullLogger()))
            ->determineReplacements($symbols);

        $this->assertTrue($moved->isDoRename());
        $this->assertSame('Company\\Project\\Moved', $moved->getLocalReplacement());

        $this->assertTrue($withDefine->isDoRename(), '`define()` constants stay global, so do not prevent moving.');

        $this->assertFalse($outside->isDoRename(), 'Not in the paths.');
        $this->assertTrue($withFunction->isDoRename());
        $this->assertSame('Company\\Project\\WithFunction', $withFunction->getLocalReplacement());
        $this->assertTrue($function->isDoRename(), 'Functions move with their file, into its classes\' namespace.');
        $this->assertSame('Company\\Project\\acme_f', $function->getLocalReplacement());
        $this->assertTrue($functionsOnly->isDoRename(), 'A file with only functions moves into the namespace given.');
        $this->assertSame('Company\\Project\\acme_g', $functionsOnly->getLocalReplacement());
        $this->assertFalse($outsideFunction->isDoRename(), 'Not in the paths.');
        $this->assertTrue($withConst->isDoRename());
        $this->assertTrue($constConstant->isDoRename(), '`const` constants move with their file.');
        $this->assertSame('Company\\Project\\ACME_C', $constConstant->getLocalReplacement());
        $this->assertFalse($defineConstant->isDoRename(), '`define()` constants are always global.');
        $this->assertFalse($mixed->isDoRename(), 'The file already declares a namespace.');
        $this->assertTrue($bracketed->isDoRename(), 'A single unnamed `namespace { }` block is given the name.');
        $this->assertSame('Company\\Project\\Bracketed', $bracketed->getLocalReplacement());
        $this->assertFalse($twoBlocks->isDoRename(), 'More than one unnamed `namespace { }` block.');
        $this->assertTrue($reserved->isDoRename());
        $this->assertSame('Company\\Project\\AcmeList', $reserved->getLocalReplacement(), '`List` is a reserved word.');
        $this->assertFalse($one->isDoRename(), 'The file\'s classes would be in different namespaces.');
        $this->assertFalse($two->isDoRename());
        $this->assertFalse($globalNamespace->isDoRename());
    }
}
