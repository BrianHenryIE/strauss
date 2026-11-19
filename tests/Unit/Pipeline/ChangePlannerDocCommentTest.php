<?php

/**
 * Where a namespaced class name is replaced in a doc comment: everywhere it is the whole name, and nowhere it is only
 * the end of a longer name, e.g. one already prefixed by an earlier run, or another package's class.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/343
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Unit\Pipeline;

use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Pipeline\ChangePlanner;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\PhpFileAnalyzer;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\ChangePlanner
 */
class ChangePlannerDocCommentTest extends TestCase
{
    protected function getFile(): File
    {
        return new File(
            'vendor/consumer/lib/src/file.php',
            'consumer/lib/src/file.php',
            'vendor-prefixed/consumer/lib/src/file.php'
        );
    }

    /**
     * `Acme\Lib\Widget` renamed to `Prefix\Acme\Lib\Widget`.
     */
    protected function getSymbols(): DiscoveredSymbols
    {
        $file = $this->getFile();

        $namespace = new NamespaceSymbol('Acme\Lib', $file);
        $namespace->setDoRename(true);
        $namespace->setLocalReplacement('Prefix\Acme\Lib');

        $widget = new ClassSymbol('Acme\Lib\Widget', $file, $namespace);
        $widget->setDoRename(true);

        return new DiscoveredSymbols([$namespace, $widget]);
    }

    /**
     * Plan the doc comment line in a file and apply the plan.
     *
     * @param string $line The doc comment line, without the leading ` * `.
     */
    protected function prefixDocCommentLine(string $line): string
    {
        $contents = "<?php\nnamespace Consumer;\n\n/**\n * " . $line . "\n */\nfunction make() {\n}\n";

        $codeMap = (new PhpFileAnalyzer())->analyze($contents);
        self::assertNotNull($codeMap);

        $plan = (new ChangePlanner())->plan($this->getSymbols(), $codeMap, $this->getFile());

        usort($plan, fn(array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($plan as $edit) {
            $contents = substr($contents, 0, $edit['start']) . $edit['replacement'] . substr($contents, $edit['end']);
        }

        self::assertSame(1, preg_match('~/\*\*\n \* (.*)\n \*/~', $contents, $matches), $contents);

        return $matches[1];
    }

    /**
     * @return array<string, array{0:string, 1:string}> The doc comment line, and the line expected after prefixing.
     */
    public static function replacedProvider(): array
    {
        return [
            'fully qualified param' => [
                '@param \Acme\Lib\Widget $widget',
                '@param \Prefix\Acme\Lib\Widget $widget',
            ],
            'qualified param' => [
                '@param Acme\Lib\Widget $widget',
                '@param Prefix\Acme\Lib\Widget $widget',
            ],
            'at the start of the line' => [
                'Acme\Lib\Widget is made here.',
                'Prefix\Acme\Lib\Widget is made here.',
            ],
            'array' => [
                '@return \Acme\Lib\Widget[]',
                '@return \Prefix\Acme\Lib\Widget[]',
            ],
            'nullable' => [
                '@return ?\Acme\Lib\Widget',
                '@return ?\Prefix\Acme\Lib\Widget',
            ],
            'union, second' => [
                '@param \Other\Thing|\Acme\Lib\Widget $widget',
                '@param \Other\Thing|\Prefix\Acme\Lib\Widget $widget',
            ],
            'union, first' => [
                '@param \Acme\Lib\Widget|null $widget',
                '@param \Prefix\Acme\Lib\Widget|null $widget',
            ],
            'intersection' => [
                '@param \Acme\Lib\Widget&\Countable $widget',
                '@param \Prefix\Acme\Lib\Widget&\Countable $widget',
            ],
            'generic' => [
                '@return array<string, \Acme\Lib\Widget>',
                '@return array<string, \Prefix\Acme\Lib\Widget>',
            ],
            'generic, first' => [
                '@return array<\Acme\Lib\Widget>',
                '@return array<\Prefix\Acme\Lib\Widget>',
            ],
            'class-string' => [
                '@param class-string<\Acme\Lib\Widget> $class',
                '@param class-string<\Prefix\Acme\Lib\Widget> $class',
            ],
            'inline see' => [
                'Made by {@see \Acme\Lib\Widget}.',
                'Made by {@see \Prefix\Acme\Lib\Widget}.',
            ],
            'method' => [
                '@see \Acme\Lib\Widget::make()',
                '@see \Prefix\Acme\Lib\Widget::make()',
            ],
            'parentheses' => [
                'A widget (\Acme\Lib\Widget).',
                'A widget (\Prefix\Acme\Lib\Widget).',
            ],
            'backticks' => [
                'Returns `\Acme\Lib\Widget`.',
                'Returns `\Prefix\Acme\Lib\Widget`.',
            ],
            'double quotes' => [
                'E.g. "\Acme\Lib\Widget".',
                'E.g. "\Prefix\Acme\Lib\Widget".',
            ],
            'single quotes' => [
                "E.g. 'Acme\\Lib\\Widget'.",
                "E.g. 'Prefix\\Acme\\Lib\\Widget'.",
            ],
            'twice' => [
                '@param \Acme\Lib\Widget $a Like Acme\Lib\Widget.',
                '@param \Prefix\Acme\Lib\Widget $a Like Prefix\Acme\Lib\Widget.',
            ],
            // By the search for names in strings, which also searches comments.
            'escaped backslashes' => [
                "E.g. 'Acme\\\\Lib\\\\Widget'.",
                "E.g. 'Prefix\\\\Acme\\\\Lib\\\\Widget'.",
            ],
            'unprefixed and already prefixed' => [
                '@param \Acme\Lib\Widget|\Prefix\Acme\Lib\Widget $widget',
                '@param \Prefix\Acme\Lib\Widget|\Prefix\Acme\Lib\Widget $widget',
            ],
        ];
    }

    /**
     * @dataProvider replacedProvider
     * @covers ::findDocCommentPositions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('replacedProvider')]
    public function test_replaced(string $line, string $expected): void
    {
        self::assertSame($expected, $this->prefixDocCommentLine($line));
    }

    /**
     * @return array<string, array{0:string}> The doc comment line, which is not changed.
     */
    public static function notReplacedProvider(): array
    {
        return [
            'already prefixed, fully qualified' => ['@throws \Prefix\Acme\Lib\Widget'],
            'already prefixed, qualified' => ['@throws Prefix\Acme\Lib\Widget'],
            'already prefixed, in quotes' => ["E.g. 'Prefix\\Acme\\Lib\\Widget'."],
            'another package\'s class, fully qualified' => ['@return \Other\Acme\Lib\Widget'],
            'another package\'s class, qualified' => ['@return Other\Acme\Lib\Widget'],
            'another package\'s class, generic' => ['@return array<\Other\Acme\Lib\Widget>'],
            'longer class name' => ['@return \Acme\Lib\WidgetFactory'],
            'longer class name with underscore' => ['@return \Acme\Lib\Widget_Factory'],
            'longer namespace name' => ['@return \MyAcme\Lib\Widget'],
            'within a null character string' => ['$name = "\0Acme\Lib\Widget\0";'],
        ];
    }

    /**
     * @dataProvider notReplacedProvider
     * @covers ::findDocCommentPositions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('notReplacedProvider')]
    public function test_not_replaced(string $line): void
    {
        self::assertSame($line, $this->prefixDocCommentLine($line));
    }
}
