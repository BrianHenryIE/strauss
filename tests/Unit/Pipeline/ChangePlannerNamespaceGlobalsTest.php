<?php

/**
 * Planning the edits for global classes moved into a namespace, as by `strauss namespace-globals`.
 *
 * @see \BrianHenryIE\Strauss\Pipeline\GlobalsNamespacer
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Unit\Pipeline;

use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Pipeline\ChangePlanner;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\PhpFileAnalyzer;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\InterfaceSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\ChangePlanner
 */
class ChangePlannerNamespaceGlobalsTest extends TestCase
{
    /**
     * `Acme_Greeting_Formal` → `Company\Project\Greeting\Formal` and `Acme_Greeter` → `Company\Project\Greeter`;
     * `Acme_Legacy` is not renamed.
     */
    protected function getSymbols(): DiscoveredSymbols
    {
        $file = new File('/project/src/file.php', 'src/file.php', '/project/src/file.php');
        $globalNamespace = new NamespaceSymbol('\\', $file);

        $formal = new ClassSymbol('Acme_Greeting_Formal', $file, $globalNamespace);
        $formal->setLocalReplacement('Company\\Project\\Greeting\\Formal');
        $formal->setDoRename(true);

        $greeter = new InterfaceSymbol('Acme_Greeter', $file, $globalNamespace);
        $greeter->setLocalReplacement('Company\\Project\\Greeter');
        $greeter->setDoRename(true);

        $legacy = new ClassSymbol('Acme_Legacy', $file, $globalNamespace);
        $legacy->setDoRename(false);

        $helper = new FunctionSymbol('acme_helper', $file, $globalNamespace);
        $helper->setLocalReplacement('Company\\Project\\acme_helper');
        $helper->setDoRename(true);

        $version = new ConstantSymbol('ACME_VERSION', $file, $globalNamespace);
        $version->setLocalReplacement('Company\\Project\\ACME_VERSION');
        $version->setDoRename(true);

        return new DiscoveredSymbols([$globalNamespace, $formal, $greeter, $legacy, $helper, $version]);
    }

    /**
     * @param string $contents The PHP to update.
     */
    protected function replace(string $contents): string
    {
        $codeMap = (new PhpFileAnalyzer())->analyze($contents);
        self::assertNotNull($codeMap);

        $edits = (new ChangePlanner())->plan($this->getSymbols(), $codeMap);

        $parts = [];
        $cursor = 0;
        foreach ($edits as $edit) {
            $parts[] = substr($contents, $cursor, $edit['start'] - $cursor);
            $parts[] = $edit['replacement'];
            $cursor = $edit['end'];
        }
        $parts[] = substr($contents, $cursor);

        return implode('', $parts);
    }

    /**
     * @covers ::findGlobalsNamespacingPositions
     * @covers ::findGlobalSymbolsPositions
     */
    public function test_moved_file(): void
    {
        $contents = <<<'EOD'
            <?php
            declare(strict_types=1);

            use Other\Library\Helper;

            /**
             * Formal.
             */
            class Acme_Greeting_Formal extends Acme_Legacy implements Acme_Greeter, Countable
            {
                public static function create(): self
                {
                    return new static();
                }

                public function count(): int
                {
                    $helper = new Helper();
                    if (!($helper instanceof Other\Library\Helper)) {
                        throw new InvalidArgumentException(strtoupper('no'));
                    }
                    return parent::count() + PHP_INT_SIZE;
                }
            }
            EOD;

        $expected = <<<'EOD'
            <?php
            declare(strict_types=1);

            namespace Company\Project\Greeting;

            use Acme_Legacy;
            use Countable;
            use InvalidArgumentException;
            use Other\Library\Helper;

            /**
             * Formal.
             */
            class Formal extends Acme_Legacy implements \Company\Project\Greeter, Countable
            {
                public static function create(): self
                {
                    return new static();
                }

                public function count(): int
                {
                    $helper = new \Other\Library\Helper();
                    if (!($helper instanceof \Other\Library\Helper)) {
                        throw new InvalidArgumentException(strtoupper('no'));
                    }
                    return parent::count() + PHP_INT_SIZE;
                }
            }
            EOD;

        self::assertSame($expected, $this->replace($contents));
    }

    /**
     * A file which stays global refers to the moved classes by their fully qualified names.
     *
     * @covers ::findGlobalSymbolsPositions
     * @covers ::findDocCommentPositions
     */
    public function test_call_site(): void
    {
        $contents = <<<'EOD'
            <?php
            /**
             * @param Acme_Greeter $greeter
             */
            function consumer(Acme_Greeter $greeter): Acme_Greeting_Formal
            {
                return Acme_Greeting_Formal::create() ?? new \Acme_Greeting_Formal();
            }
            EOD;

        $expected = <<<'EOD'
            <?php
            /**
             * @param \Company\Project\Greeter $greeter
             */
            function consumer(\Company\Project\Greeter $greeter): \Company\Project\Greeting\Formal
            {
                return \Company\Project\Greeting\Formal::create() ?? new \Company\Project\Greeting\Formal();
            }
            EOD;

        self::assertSame($expected, $this->replace($contents));
    }

    /**
     * @covers ::findGlobalSymbolsPositions
     */
    public function test_namespaced_call_site_use_statement(): void
    {
        $contents = <<<'EOD'
            <?php
            namespace Other;

            use Acme_Greeter;

            function consumer(Acme_Greeter $greeter) {}
            EOD;

        $expected = <<<'EOD'
            <?php
            namespace Other;

            use Company\Project\Greeter as Acme_Greeter;

            function consumer(Acme_Greeter $greeter) {}
            EOD;

        self::assertSame($expected, $this->replace($contents));
    }

    /**
     * Class names in strings, and the prefixes used to build them, with backslashes doubled.
     *
     * @covers ::findSymbolPositionsInStrings
     * @covers ::findMovedClassPrefixStringPositions
     * @covers ::getMovedClassPrefixes
     */
    public function test_strings(): void
    {
        $contents = <<<'EOD'
            <?php
            $a = 'Acme_Greeter';
            $b = "Acme_Greeting_Formal";
            $c = 'Acme_Greeting_' . $type;
            $d = "Acme_" . $type;
            $e = 'Acme_Greeting';
            $f = 'Acme_Legacy_';
            EOD;

        $expected = <<<'EOD'
            <?php
            $a = 'Company\\Project\\Greeter';
            $b = "Company\\Project\\Greeting\\Formal";
            $c = 'Company\\Project\\Greeting\\' . $type;
            $d = "Company\\Project\\" . $type;
            $e = 'Acme_Greeting';
            $f = 'Acme_Legacy_';
            EOD;

        self::assertSame($expected, $this->replace($contents));
    }

    /**
     * The `namespace` insertion and a name at the same position are one edit.
     *
     * @covers ::plan
     * @covers ::findGlobalsNamespacingPositions
     */
    public function test_insertion_at_the_position_of_another_edit(): void
    {
        $contents = "<?php\nException::class;\nclass Acme_Greeter {}\n";

        $expected = "<?php\nnamespace Company\\Project;\n\nuse Exception;\n\nException::class;\nclass Greeter {}\n";

        self::assertSame($expected, $this->replace($contents));
    }

    /**
     * A file which declares no moved class gets no `namespace`.
     *
     * @covers ::findGlobalsNamespacingPositions
     */
    public function test_file_without_moved_classes_is_not_namespaced(): void
    {
        $contents = "<?php\nclass Acme_Legacy extends Exception {}\n";

        self::assertSame($contents, $this->replace($contents));
    }

    /**
     * @return array<string, array{0:string, 1:string}> Contents, expected.
     */
    public static function bracketedGlobalNamespaceDataProvider(): array
    {
        return [
            'the unnamed block is named' => [
                "<?php\nnamespace {\n    class Acme_Greeting_Formal extends Exception implements Acme_Greeter {}\n}\n",
                "<?php\nnamespace Company\\Project\\Greeting {\n    use Exception;\n\n    class Formal extends Exception implements \\Company\\Project\\Greeter {}\n}\n",
            ],
            'after a declare' => [
                "<?php\ndeclare(strict_types=1);\n\nnamespace {\n    class Acme_Greeter {}\n}\n",
                "<?php\ndeclare(strict_types=1);\n\nnamespace Company\\Project {\n    class Greeter {}\n}\n",
            ],
            'no space before the brace' => [
                "<?php\nnamespace{ class Acme_Greeter {} }\n",
                "<?php\nnamespace Company\\Project{ class Greeter {} }\n",
            ],
            'only a function' => [
                "<?php\nnamespace {\n    function acme_helper() {}\n}\n",
                "<?php\nnamespace Company\\Project {\n    function acme_helper() {}\n}\n",
            ],
        ];
    }

    /**
     * A file whose only namespace is one unnamed `namespace { }` block: the block is given the name, rather than a
     * `namespace` declaration being inserted before it, which PHP cannot compile.
     *
     * @covers ::findGlobalsNamespacingPositions
     * @dataProvider \BrianHenryIE\Strauss\Tests\Unit\Pipeline\ChangePlannerNamespaceGlobalsTest::bracketedGlobalNamespaceDataProvider
     *
     * @param string $contents The PHP to update.
     * @param string $expected The PHP after.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bracketedGlobalNamespaceDataProvider')]
    public function test_bracketed_global_namespace(string $contents, string $expected): void
    {
        self::assertSame($expected, $this->replace($contents));
    }

    /**
     * A file with more than one unnamed `namespace { }` block is not given a namespace.
     *
     * @covers ::findGlobalsNamespacingPositions
     */
    public function test_two_bracketed_global_namespaces_are_not_named(): void
    {
        $contents = "<?php\nnamespace { class Acme_Greeter {} }\nnamespace { echo 1; }\n";

        $result = $this->replace($contents);

        self::assertStringNotContainsString('namespace Company', $result);
        self::assertStringContainsString("namespace { echo 1; }\n", $result);
    }

    /**
     * @return array<string, array{0:string, 1:string}> Contents, expected.
     */
    public static function globalClassImportsDataProvider(): array
    {
        return [
            'used in code and in doc comments, sorted, once each' => [
                "<?php\n/**\n * @param WP_Post \$post\n * @throws Exception\n */\nfunction acme_helper(WP_Post \$post, ArrayObject \$list): WP_Post { throw new Exception(); }\n",
                "<?php\nnamespace Company\\Project;\n\nuse ArrayObject;\nuse Exception;\nuse WP_Post;\n\n/**\n * @param WP_Post \$post\n * @throws Exception\n */\nfunction acme_helper(WP_Post \$post, ArrayObject \$list): WP_Post { throw new Exception(); }\n",
            ],
            'only in a doc comment' => [
                "<?php\nclass Acme_Greeter {\n    /**\n     * @var WP_Post|null\n     * @return array<int, WP_Error>|string|Array|T\n     * @template T\n     * @see https://example.org/Docs and {@see WP_Query::get()}\n     */\n    public \$post;\n}\n",
                "<?php\nnamespace Company\\Project;\n\nuse WP_Error;\nuse WP_Post;\nuse WP_Query;\n\nclass Greeter {\n    /**\n     * @var WP_Post|null\n     * @return array<int, WP_Error>|string|Array|T\n     * @template T\n     * @see https://example.org/Docs and {@see WP_Query::get()}\n     */\n    public \$post;\n}\n",
            ],
            'already fully qualified, and names which are not global classes' => [
                "<?php\nuse Vendor\\Thing;\nclass Acme_Greeter extends \\Exception implements Vendor\\Contract { public function a(Thing \$t, self \$s): static {} }\n",
                "<?php\nnamespace Company\\Project;\n\nuse Vendor\\Thing;\nclass Greeter extends \\Exception implements \\Vendor\\Contract { public function a(\\Vendor\\Thing \$t, self \$s): static {} }\n",
            ],
            'already imported' => [
                "<?php\nuse Exception;\nclass Acme_Greeter extends Exception {}\n",
                "<?php\nnamespace Company\\Project;\n\nuse Exception;\nclass Greeter extends Exception {}\n",
            ],
            'before the file\'s own imports' => [
                "<?php\nuse Vendor\\Thing;\n\nclass Acme_Greeter extends Exception {}\n",
                "<?php\nnamespace Company\\Project;\n\nuse Exception;\nuse Vendor\\Thing;\n\nclass Greeter extends Exception {}\n",
            ],
            'the name is declared in the file: qualified instead' => [
                "<?php\nclass Acme_Greeter extends Greeter {}\n",
                "<?php\nnamespace Company\\Project;\n\nclass Greeter extends \\Greeter {}\n",
            ],
            'the name is another import\'s alias: qualified instead' => [
                "<?php\nuse Vendor\\Thing as Exception;\nclass Acme_Greeter { /** @throws Exception */ public function a(): Exception {} }\n",
                "<?php\nnamespace Company\\Project;\n\nuse Vendor\\Thing as Exception;\nclass Greeter { /** @throws Exception */ public function a(): \\Vendor\\Thing {} }\n",
            ],
            'a global class which is not moved' => [
                "<?php\nclass Acme_Greeter extends Acme_Legacy {}\n",
                "<?php\nnamespace Company\\Project;\n\nuse Acme_Legacy;\n\nclass Greeter extends Acme_Legacy {}\n",
            ],
            'in an unnamed namespace block' => [
                "<?php\nnamespace {\n    class Acme_Greeter extends Exception implements Countable {}\n}\n",
                "<?php\nnamespace Company\\Project {\n    use Countable;\n    use Exception;\n\n    class Greeter extends Exception implements Countable {}\n}\n",
            ],
        ];
    }

    /**
     * Global classes which are not moved are imported, `use Exception;`, so the names in the code and in the doc
     * comments keep referring to them.
     *
     * @covers ::findGlobalsNamespacingPositions
     * @covers ::getGlobalClassesToImport
     * @covers ::getClassNamesInDocComments
     * @dataProvider \BrianHenryIE\Strauss\Tests\Unit\Pipeline\ChangePlannerNamespaceGlobalsTest::globalClassImportsDataProvider
     *
     * @param string $contents The PHP to update.
     * @param string $expected The PHP after.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('globalClassImportsDataProvider')]
    public function test_global_classes_are_imported(string $contents, string $expected): void
    {
        self::assertSame($expected, $this->replace($contents));
    }

    /**
     * A file with only a function moves too.
     *
     * @covers ::findFunctionPositions
     * @covers ::findGlobalsNamespacingPositions
     */
    public function test_moved_function(): void
    {
        $contents = <<<'EOD'
            <?php
            if (!function_exists('acme_helper')) {
                function acme_helper(): ArrayObject
                {
                    return new ArrayObject([strlen('x')]);
                }
            }
            EOD;

        $expected = <<<'EOD'
            <?php
            namespace Company\Project;

            use ArrayObject;

            if (!function_exists('Company\\Project\\acme_helper')) {
                function acme_helper(): ArrayObject
                {
                    return new ArrayObject([strlen('x')]);
                }
            }
            EOD;

        self::assertSame($expected, $this->replace($contents));
    }

    /**
     * @covers ::findFunctionPositions
     * @covers ::findFunctionPositionsInDocComment
     * @covers ::findNamespacePositions
     */
    public function test_moved_function_call_sites(): void
    {
        $contents = <<<'EOD'
            <?php
            /**
             * Uses `acme_helper()`.
             */
            function consumer() {
                set_error_handler("acme_helper");
                return acme_helper() ?? \acme_helper();
            }
            EOD;

        $expected = <<<'EOD'
            <?php
            /**
             * Uses `\Company\Project\acme_helper()`.
             */
            function consumer() {
                set_error_handler("Company\\Project\\acme_helper");
                return \Company\Project\acme_helper() ?? \Company\Project\acme_helper();
            }
            EOD;

        self::assertSame($expected, $this->replace($contents));

        $namespaced = "<?php\nnamespace Other;\n\nuse function acme_helper;\n\nacme_helper();\n";
        $namespacedExpected = "<?php\nnamespace Other;\n\nuse function Company\\Project\\acme_helper as acme_helper;\n\n\\Company\\Project\\acme_helper();\n";

        self::assertSame($namespacedExpected, $this->replace($namespaced));
    }

    /**
     * A file with only a constant moves too; uses become fully qualified.
     *
     * @covers ::findConstantPositions
     * @covers ::findGlobalsNamespacingPositions
     */
    public function test_moved_constant(): void
    {
        $contents = "<?php\nconst ACME_VERSION = '1.0';\n";
        $expected = "<?php\nnamespace Company\\Project;\n\nconst ACME_VERSION = '1.0';\n";

        self::assertSame($expected, $this->replace($contents));

        $callSite = "<?php\nif (defined(\"ACME_VERSION\")) {\n    echo ACME_VERSION . \\ACME_VERSION . PHP_EOL;\n}\n";
        $callSiteExpected = "<?php\nif (defined(\"Company\\\\Project\\\\ACME_VERSION\")) {\n    echo \\Company\\Project\\ACME_VERSION . \\Company\\Project\\ACME_VERSION . PHP_EOL;\n}\n";

        self::assertSame($callSiteExpected, $this->replace($callSite));

        $namespaced = "<?php\nnamespace Other;\n\nuse const ACME_VERSION;\n";
        $namespacedExpected = "<?php\nnamespace Other;\n\nuse const Company\\Project\\ACME_VERSION;\n";

        self::assertSame($namespacedExpected, $this->replace($namespaced));
    }

    /**
     * An import whose name is the same as a moved class's new short name would collide with it. Every reference in
     * the moved file is fully qualified, so the import is given an alias.
     *
     * @covers ::findGlobalsNamespacingPositions
     */
    public function test_import_colliding_with_moved_class_is_aliased(): void
    {
        $contents = <<<'EOD'
            <?php
            use Other\Library\Greeter;
            use Other\Library\Unrelated;
            use function Other\Library\acme_helper;

            interface Acme_Greeter extends Greeter {}

            function acme_helper(): Unrelated { return new Unrelated(); }
            EOD;

        $expected = <<<'EOD'
            <?php
            namespace Company\Project;

            use Other\Library\Greeter as GreeterImport;
            use Other\Library\Unrelated;
            use function Other\Library\acme_helper as acme_helperImport;

            interface Greeter extends \Other\Library\Greeter {}

            function acme_helper(): \Other\Library\Unrelated { return new \Other\Library\Unrelated(); }
            EOD;

        self::assertSame($expected, $this->replace($contents));
    }

    /**
     * An unqualified call or constant fetch which refers to an import, not to the global function or constant being
     * moved, keeps referring to the import.
     *
     * @covers ::findFunctionPositions
     * @covers ::findConstantPositions
     * @covers ::findGlobalsNamespacingPositions
     */
    public function test_imported_function_and_constant_with_moved_names(): void
    {
        // In a moved file, the colliding imports are aliased, so the uses are qualified with the imported names.
        $contents = <<<'EOD'
            <?php
            use function Other\Library\acme_helper;
            use const Other\Library\ACME_VERSION;

            function acme_helper() { return acme_helper() . ACME_VERSION; }
            const ACME_VERSION = '1.0';
            EOD;

        $expected = <<<'EOD'
            <?php
            namespace Company\Project;

            use function Other\Library\acme_helper as acme_helperImport;
            use const Other\Library\ACME_VERSION as ACME_VERSIONImport;

            function acme_helper() { return \Other\Library\acme_helper() . \Other\Library\ACME_VERSION; }
            const ACME_VERSION = '1.0';
            EOD;

        self::assertSame($expected, $this->replace($contents));

        // A file which is not moved is unchanged.
        $callSite = "<?php\nuse function Other\\Library\\acme_helper;\nuse const Other\\Library\\ACME_VERSION;\n\necho acme_helper() . ACME_VERSION;\n";

        self::assertSame($callSite, $this->replace($callSite));
    }
}
