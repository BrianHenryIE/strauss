<?php

namespace BrianHenryIE\Strauss;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\FlysystemReadOnly\ReadOnlyFileSystemAdapter;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Helpers\Log\RelativeFilepathLogProcessor;
use Composer\Util\Platform;
use Elazar\Flystream\FilesystemRegistry;
use League\Flysystem\Config;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\Filesystem as FlysystemFileSystem;
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

    protected FileSystem $filesystem;

    protected FileSystem $inMemoryFilesystem;

    protected $previous_error_handler;

    protected function setUp(): void
    {
        parent::setUp();

        /**
         * PHP 8.6: "Returning a value from a constructor is deprecated".
         * But it doesn't look like there is a value being returned.
         *
         * @see Mockery\Loader\EvalLoader
         */
        $this->previous_error_handler = set_error_handler(function (int $errNo, string $errstr, string $errFile, int $errLine): bool {
            if ('Returning a value from a constructor is deprecated' === $errstr) {
                debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
                return true;
            }
            return is_callable($this->previous_error_handler)
                ? call_user_func_array($this->previous_error_handler, func_get_args())
                : false;
        }, E_DEPRECATED | E_USER_DEPRECATED);
    }

    public static function assertEqualsRN($expected, $actual, string $message = ''): void
    {
        if (is_string($expected) && is_string($actual)) {
            $expected = str_replace("\r\n", "\n", $expected);
            $actual   = str_replace("\r\n", "\n", $actual);
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
        $string = str_replace("\\n", '', $string);
        $string = implode(PHP_EOL, array_map('trim', explode(PHP_EOL, $string)));

        return trim($string);
    }

    protected function getFileSystem(): FileSystem
    {

        if (! isset($this->filesystem)) {
            $this->filesystem = $this->getNewFileSystem();
        }

        return $this->filesystem;
    }

    protected function getNewFileSystem(): FileSystem
    {
        set_error_handler(function () {
        }, E_DEPRECATED | E_USER_DEPRECATED);

        try {
            $workingDir = isset($this->testsWorkingDir) ? $this->testsWorkingDir : getcwd();

            $localFilesystemAdapter = new LocalFilesystemAdapter(
                FileSystem::getFsRoot($workingDir),
                null,
                LOCK_EX,
                LocalFilesystemAdapter::SKIP_LINKS
            );

            $filesystem = new FileSystem(
                new FlysystemFileSystem(
                    $localFilesystemAdapter,
                    [
                        Config::OPTION_DIRECTORY_VISIBILITY => 'public',
                    ],
                    Filesystem::makePathNormalizer($workingDir)
                ),
                $workingDir
            );
        } finally {
            restore_error_handler();
        }

        return $filesystem;
    }

    /**
     * Get an in-memory filesystem.
     */
    protected function getInMemoryFileSystem(): FileSystem
    {
        if (! isset($this->inMemoryFilesystem)) {
            $this->inMemoryFilesystem = $this->getNewInMemoryFileSystem();
        }

        return $this->inMemoryFilesystem;
    }

    protected function getNewInMemoryFileSystem(): FileSystem
    {
        set_error_handler(function () {
        }, E_DEPRECATED | E_USER_DEPRECATED);

        $inMemoryFilesystemAdapter = new InMemoryFilesystemAdapter();

        $normalizer = Filesystem::makePathNormalizer('mem://');

        $readonlyFilesystemAdapter = new ReadOnlyFileSystemAdapter(
            $inMemoryFilesystemAdapter,
            Filesystem::makePathNormalizer('mem://')
        );

        $filesystem = new FileSystem(
            new FlysystemFileSystem(
                $readonlyFilesystemAdapter,
                [
                    Config::OPTION_DIRECTORY_VISIBILITY => 'public',
                ],
                $normalizer
            ),
            'mem://',
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

        restore_error_handler();

        return $filesystem;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        restore_error_handler();

        try {
            /** @var FilesystemRegistry $registry */
            $registry = \Elazar\Flystream\ServiceLocator::get(\Elazar\Flystream\FilesystemRegistry::class);
            $registry->unregister('mem');
        } catch (\Exception $e) {
        }

        Mockery::close();
    }

    /**
     * Use this method when passing the logger to a class constructor.
     *
     * @return LoggerInterface&Logger
     */
    protected function getLogger(): LoggerInterface
    {
        if (! isset($this->logger)) {
            $this->logger = $this->getNewLogger();
        }

        return $this->logger;
    }

    protected function getNewLogger(): LoggerInterface
    {
        $logger = new Logger('logger');
        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushProcessor(RelativeFilepathLogProcessor::make($this->getInMemoryFileSystem()));
        $logger->pushHandler(new PsrHandler($this->getTestLogger()));

        return $logger;
    }

    /**
     * Use this method to retrieve the test logger for assertions.
     */
    protected function getTestLogger(): TestLogger
    {
        if (! isset($this->testLogger)) {
            $this->testLogger = new ColorLogger();
        }

        return $this->testLogger;
    }

    protected function markTestSkippedOnWindows(string $message = 'Skipped on Windows'): void
    {
        if (Platform::isWindows()) {
            $this->markTestSkipped($message);
        }
    }
}
