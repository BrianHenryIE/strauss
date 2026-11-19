<?php

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\InterfaceSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\SymbolMentionFilter
 */
class SymbolMentionFilterTest extends TestCase
{
    protected function getSut(): SymbolMentionFilter
    {
        $file = new File('/vendor/psr/log/src/LoggerInterface.php', 'psr/log/src/LoggerInterface.php', '/vendor/psr/log/src/LoggerInterface.php');

        $namespace = new NamespaceSymbol('Psr\Log', $file);
        $global = new NamespaceSymbol('\\', $file);

        return new SymbolMentionFilter(
            new DiscoveredSymbols(
                [
                    $namespace,
                    $global,
                    new InterfaceSymbol('Psr\Log\LoggerInterface', $file, $namespace),
                    new ClassSymbol('Legacy_Logger', $file, $global),
                    new FunctionSymbol('legacy_log', $file, $global),
                    new ConstantSymbol('LEGACY_LOG_LEVEL', $file, $global),
                ]
            )
        );
    }

    /**
     * The first part of each namespace, and the global symbols' names.
     *
     * @covers ::__construct
     * @covers ::getNames
     */
    public function test_names(): void
    {
        self::assertSame(
            ['LEGACY_LOG_LEVEL', 'Legacy_Logger', 'Psr', 'legacy_log'],
            $this->getSut()->getNames()
        );
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function mentionedProvider(): array
    {
        return [
            'use statement' => ['<?php use Psr\Log\LoggerInterface;'],
            'fully qualified' => ['<?php function f(\Psr\Log\LoggerInterface $logger) {}'],
            'group use' => ['<?php use Psr\{Log\LoggerInterface, Log\NullLogger};'],
            'lower case' => ['<?php use psr\log\LoggerInterface;'],
            'string' => ['<?php $class = "Psr\\\\Log\\\\LoggerInterface";'],
            'global class' => ['<?php new Legacy_Logger();'],
            'global function' => ['<?php legacy_log("x");'],
            'global constant' => ['<?php echo LEGACY_LOG_LEVEL;'],
        ];
    }

    /**
     * @dataProvider mentionedProvider
     * @covers ::isMentionedIn
     * @covers ::getPatterns
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mentionedProvider')]
    public function test_mentioned(string $contents): void
    {
        self::assertTrue($this->getSut()->isMentionedIn($contents));
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function notMentionedProvider(): array
    {
        return [
            'unrelated code' => ['<?php namespace Monolog; class Logger {}'],
            'part of a longer word' => ['<?php class PsrLogger { function legacy_loggers() {} }'],
            'blade template' => ["@extends('errors::minimal')\n<div>{{ \$message }}</div>"],
        ];
    }

    /**
     * @dataProvider notMentionedProvider
     * @covers ::isMentionedIn
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('notMentionedProvider')]
    public function test_not_mentioned(string $contents): void
    {
        self::assertFalse($this->getSut()->isMentionedIn($contents));
    }

    /**
     * @covers ::isMentionedIn
     */
    public function test_no_symbols_mentions_nothing(): void
    {
        self::assertFalse((new SymbolMentionFilter(new DiscoveredSymbols()))->isMentionedIn('<?php echo "anything";'));
    }

    /**
     * More names than fit in one pattern are split across several.
     *
     * @covers ::getPatterns
     */
    public function test_many_names(): void
    {
        $file = new File('/vendor/acme/functions.php', 'acme/functions.php', '/vendor/acme/functions.php');
        $global = new NamespaceSymbol('\\', $file);

        $symbols = [$global];
        for ($i = 0; $i < 600; $i++) {
            $symbols[] = new FunctionSymbol('acme_function_' . $i, $file, $global);
        }

        $sut = new SymbolMentionFilter(new DiscoveredSymbols($symbols));

        self::assertTrue($sut->isMentionedIn('<?php acme_function_599();'));
        self::assertFalse($sut->isMentionedIn('<?php acme_function_600();'));
    }
}
