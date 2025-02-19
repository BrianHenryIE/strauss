<?php

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Helpers\FileSystem;
use Elazar\Flystream\FilesystemRegistry;
use League\Flysystem\Config;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

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
        return trim($string);
    }

    protected function getFileSystem(): FileSystem
    {

        $inMemoryFilesystem = new \BrianHenryIE\Strauss\Helpers\InMemoryFilesystemAdapter();

        $filesystem = new Filesystem(
            new \League\Flysystem\Filesystem(
                $inMemoryFilesystem,
                [
                    Config::OPTION_DIRECTORY_VISIBILITY => 'public',
                ],
                new \Elazar\Flystream\StripProtocolPathNormalizer()
            )
        );

        /** @var FilesystemRegistry $registry */
        $registry = \Elazar\Flystream\ServiceLocator::get(\Elazar\Flystream\FilesystemRegistry::class);
        // Register a file stream mem:// to handle file operations by third party libraries.
        // This exception handling probably doesn't matter in real life but does in unit tests.
        try {
            $registry->get('mem');
        } catch (\Exception $e) {
            $registry->register('mem', $filesystem);
        }

        return $filesystem;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        /** @var FilesystemRegistry $registry */
        try {
            $registry = \Elazar\Flystream\ServiceLocator::get(\Elazar\Flystream\FilesystemRegistry::class);
            $registry->unregister('mem');
        } catch (\Exception $e) {
        }
    }
}
