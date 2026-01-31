<?php

namespace BrianHenryIE\Strauss;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Helpers\Log\RelativeFilepathLogProcessor;
use Elazar\Flystream\FilesystemRegistry;
use Elazar\Flystream\StripProtocolPathNormalizer;
use League\Flysystem\Config;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\WhitespacePathNormalizer;
use Mockery;
use Monolog\Handler\PsrHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;
use Psr\Log\Test\TestLogger;

class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * The logger used by the objects.
     */
    protected ?LoggerInterface $logger;

    /**
     * The output logger.
     */
    protected TestLogger $testLogger;

    protected FileSystem $inMemoryFilesystem;

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

    public static function assertStringContainsStringRemoveBlankLinesLeadingWhitespace($expected, $actual, string $message = ''): void
    {
        self::assertStringContainsString(
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
        $string = implode(PHP_EOL, array_map('trim', explode(PHP_EOL, $string)));
        return trim($string);
    }

    protected function getFileSystem(): Filesystem
    {

        if (!isset($this->filesystem)) {
            $this->filesystem = $this->getNewFileSystem();
        }
        return $this->filesystem;
    }

    protected function getNewFileSystem(): Filesystem
    {
        $localFilesystemAdapter = new LocalFilesystemAdapter(
            '/',
            null,
            LOCK_EX,
            LocalFilesystemAdapter::SKIP_LINKS
        );

        $normalizer = new WhitespacePathNormalizer();

        return new FileSystem(
            new \League\Flysystem\Filesystem(
                $localFilesystemAdapter,
                [
                    Config::OPTION_DIRECTORY_VISIBILITY => 'public',
                ],
                $normalizer
            ),
            isset($this->testsWorkingDir) ? $this->testsWorkingDir : getcwd()
        );
    }

    /**
     * Get an in-memory filesystem.
     */
    protected function getInMemoryFileSystem(): FileSystem
    {
        if (!isset($inMemoryFilesystem)) {
            $this->inMemoryFilesystem = $this->getNewInMemoryFileSystem();
        }
        return $this->inMemoryFilesystem;
    }

    protected function getNewInMemoryFileSystem(): FileSystem
    {

        $inMemoryFilesystem = new \BrianHenryIE\Strauss\Helpers\InMemoryFilesystemAdapter();

        $normalizer = new WhitespacePathNormalizer();
        $normalizer = new StripProtocolPathNormalizer(['mem'], $normalizer);

        $filesystem = new Filesystem(
            new \League\Flysystem\Filesystem(
                $inMemoryFilesystem,
                [
                    Config::OPTION_DIRECTORY_VISIBILITY => 'public',
                ],
                $normalizer
            ),
            'mem://'
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

        Mockery::close();
    }

    /**
     * Use this method when passing the logger to a class constructor.
     */
    protected function getLogger(): LoggerInterface
    {
        if (!isset($this->logger)) {
            $this->logger = $this->getNewLogger();
        }
        return $this->logger;
    }
    protected function getNewLogger(): LoggerInterface
    {
        $logger = new Logger('logger');
        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushProcessor(new RelativeFilepathLogProcessor($this->getInMemoryFileSystem()));
        $logger->pushHandler(new PsrHandler($this->getTestLogger()));
        return $logger;
    }

    /**
     * Use this method to retrieve the test logger for assertions.
     */
    protected function getTestLogger(): TestLogger
    {
        if (!isset($this->testLogger)) {
            $this->testLogger = new ColorLogger();
        }

        return $this->testLogger;
    }
}
