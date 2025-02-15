<?php

namespace BrianHenryIE\Strauss;

class TestCase extends \PHPUnit\Framework\TestCase
{
    public static function assertEqualsRN($expected, $actual, string $message = ''): void
    {
        if (is_string($expected) && is_string($actual)) {
            $expected = str_replace("\r\n", "\n", $expected);
            $actual = str_replace("\r\n", "\n", $actual);
        }

        self::assertEquals($expected, $actual, $message);
    }

    public static function assertEqualsRemoveBlankLinesLeadingWhitespace($expected, $actual, string $message = ''): void
    {
        if (is_string($expected) && is_string($actual)) {
            $expected = str_replace("\r\n", "\n", $expected);
            $actual = str_replace("\r\n", "\n", $actual);

            $expected = preg_replace('/^\s*/m', '', $expected);
            $actual = preg_replace('/^\s*/m', '', $actual);

            $expected = preg_replace('/\n\s*\n/', "\n", $expected);
            $actual = preg_replace('/\n\s*\n/', "\n", $actual);
        }

        self::assertEquals($expected, $actual, $message);
    }
}
