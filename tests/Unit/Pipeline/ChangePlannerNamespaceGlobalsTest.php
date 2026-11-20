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

            use Other\Library\Helper;

            /**
             * Formal.
             */
            class Formal extends \Acme_Legacy implements \Company\Project\Greeter, \Countable
            {
                public static function create(): self
                {
                    return new static();
                }

                public function count(): int
                {
                    $helper = new \Other\Library\Helper();
                    if (!($helper instanceof \Other\Library\Helper)) {
                        throw new \InvalidArgumentException(strtoupper('no'));
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

        $expected = "<?php\nnamespace Company\\Project;\n\n\\Exception::class;\nclass Greeter {}\n";

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

            if (!function_exists('Company\\Project\\acme_helper')) {
                function acme_helper(): \ArrayObject
                {
                    return new \ArrayObject([strlen('x')]);
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
}
