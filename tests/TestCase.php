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
        self::assertEquals(
            self::stripWhitespaceAndBlankLines($expected),
            self::stripWhitespaceAndBlankLines($actual),
            $message
        );
    }

    protected static function stripWhitespaceAndBlankLines(string $string): string
    {
        $string = str_replace("\r\n", "\n", $string);
        $string = preg_replace('/^\s*/m', '', $string);
        $string = preg_replace('/\n\s*\n/', "\n", $string);
        return $string;
    }
}
