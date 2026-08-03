<?php
/**
 * "unit" tests should not write to the filesystem.
 */

namespace BrianHenryIE\Strauss;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\FlysystemReadOnly\InMemoryFilesystemAdapter;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\Helpers\Flysystem\PathPrefixer;
use BrianHenryIE\FlysystemReadOnly\ReadOnlyFileSystemAdapter;
use BrianHenryIE\Strauss\Helpers\Log\RelativeFilepathLogProcessor;
use Composer\Util\Platform;
use Elazar\Flystream\FilesystemRegistry;
use Elazar\Flystream\ServiceLocator;
use Exception;
use League\Flysystem\Config;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PathNormalizer;
use Mockery;
use Monolog\Handler\PsrHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;
use Psr\Log\Test\TestLogger;
use stdClass;

class TestCase extends \PHPUnit\Framework\TestCase
{
    use CustomUnitTestAssertionsTrait;
    use MarkTestsSkippedTrait;

    protected string $projectDir;

    /**
     * The logger used by the objects.
     */
    public ?LoggerInterface $logger;

    /**
     * The output logger.
     */
    protected ?TestLogger $testLogger;

    protected PathNormalizer $pathNormalizer;

    /**
     * A readonly filesystem for reading test fixtures.
     */
    protected FileSystem $fixturesFilesystem;

    protected FileSystem $inMemoryFilesystem;

    protected FileSystem $filesystem;

    protected string $testsWorkingDir;

    public bool $allowErrorLogs = false;

    public ?bool $allowWarningLogs = false;

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

        $this->projectDir = Platform::getcwd();

        /**
         * We need to register the mem stream wrapper before the static methods in Composer are called.
         *
         * @see \Composer\Util\Filesystem::$streamWrappersRegex
         * @see \Composer\Util\Filesystem::isStreamWrapperPath()
         */
        if (!in_array('mem', stream_get_wrappers())
            && method_exists(\Composer\Util\Filesystem::class, 'isStreamWrapperPath')
        ) {
            stream_wrapper_register('mem', stdClass::class);
            \Composer\Util\Filesystem::isStreamWrapperPath('mem://');
            stream_wrapper_unregister('mem');
        }

        /**
         * Tests are passing individually but failing when run as a group. Let's avoid running the path where it fails.
         *
         * {@see VersionGuesser::guessVersion()} can be short circuited in {@see Platform::isInputCompletionProcess()}.
         * `$_SERVER['argv'][1] = '_complete'`
         */
        array_splice($_SERVER['argv'], 1, 0, '_complete');
    }

    protected function tearDown(): void
    {
        restore_error_handler();

        parent::tearDown();

        Mockery::close();

        if (in_array('mem', stream_get_wrappers())) {
            /** @var FilesystemRegistry $registry */
            $registry = ServiceLocator::get(FilesystemRegistry::class);
            try {
                /**
                 * Also runs `stream_wrapper_unregister('mem')`
                 */
                $registry->unregister('mem');
            } catch (Exception $e) {
            }
        }

        // When testing with the phar we're not able to set the logger.
        if (!$this->isTestingWithPhar()) {
            /**
             * @param string $level
             *
             * @return string[]
             */
            $levelMessages = function (string $level): array {
                if (!isset($this->getTestLogger()->recordsByLevel[$level])) {
                    return array();
                }
                return array_map(
                    /** @param array{level:string, message:string, context:array} $record */
                    fn(array $record) => $record['message'],
                    $this->getTestLogger()->recordsByLevel[$level]
                );
            };

            if ($this->allowErrorLogs === false) {
                $this->assertFalse(
                    $this->getTestLogger()->hasErrorRecords(),
                    "Unexpected TestLogger::hasErrorRecords() logged: \"" . implode("\",\n\"", $levelMessages('error')) . '"'
                );
            } else {
                $this->assertTrue($this->getTestLogger()->hasErrorRecords(), "Expected TestLogger::hasErrorRecords() but there were none.");
            }
            if (null !== $this->allowWarningLogs) {
                if ($this->allowWarningLogs === false) {
                    $this->assertFalse(
                        $this->getTestLogger()->hasWarningRecords(),
                        "Unexpected TestLogger::hasWarningRecords() logged: \"" . implode("\",\n\"", $levelMessages('warning')) . '"'
                    );
                } else {
                    $this->assertTrue($this->getTestLogger()->hasWarningRecords(), "Expected TestLogger::hasWarningRecords() but there were none.");
                }
            }
        }

        unset($this->logger);
        unset($this->testLogger);
        unset($this->inMemoryFilesystem);
        unset($this->filesystem);
        unset($this->fixturesFilesystem);
    }

    protected function expectWarningLogs(): void
    {
        $this->allowWarningLogs = true;
    }

    /**
     * When we care neither have some been logged nor when none have been logged.
     */
    protected function ignoreWarningLogs(): void
    {
        $this->allowWarningLogs = null;
    }

    protected function expectErrorLogs(): void
    {
        $this->allowErrorLogs = true;
    }

    protected function isPhar(): bool
    {
        return file_exists($this->projectDir . '/strauss.phar');
    }

    protected function isTestingWithPhar(): bool
    {
        return $this->isPhar() && $this instanceof IntegrationTestCase;
    }

    protected static function stripWhitespaceAndBlankLines(string $string): string
    {
        $string = str_replace("\r\n", "\n", $string);
        $string = preg_replace('/^\s*/m', '', $string) ?? $string;
        $string = preg_replace('/\n\s*\n/', "\n", $string) ?? $string;
        $string = str_replace("\\n", '', $string);
        $string = implode(PHP_EOL, array_map('trim', explode(PHP_EOL, $string)));

        return trim($string);
    }

    protected function isPhpStormRunning(): bool
    {
        if (isset($_SERVER['__CFBundleIdentifier']) && $_SERVER['__CFBundleIdentifier'] == 'com.jetbrains.PhpStorm') {
            return true;
        }

        if (isset($_SERVER['IDE_PHPUNIT_CUSTOM_LOADER'])) {
            return true;
        }
        return false;
    }

    protected function getFixturesFilesystem(): FileSystem
    {
        if (!isset($this->fixturesFilesystem)) {
            $projectFsAdapter = new LocalFilesystemAdapter(
                FileSystem::getFsRoot(__FILE__)
            );
            $readonlyFsAdapter = new ReadOnlyFileSystemAdapter(
                $projectFsAdapter
            );
            $this->fixturesFilesystem = new FileSystem(
                $readonlyFsAdapter,
                [],
                FileSystem::makePathNormalizer(__FILE__),
                new PathPrefixer(FileSystem::getFsRoot(__FILE__), DIRECTORY_SEPARATOR)
            );
        }
        return $this->fixturesFilesystem;
    }

    /**
     * This is for unit test to instantiate objects and query changes.
     * It is not for loading fixtures.
     */
    protected function getFileSystem(): FileSystem
    {
        /**
         * TODO: Only needed until we can use an in memory filesystem for tests.
         *
         * @see https://github.com/composer/composer/pull/12396
         */
        return $this->getTestsWorkingDirectoryFileSystem();

        if (! isset($this->filesystem)) {
            $this->filesystem = $this->getInMemoryFileSystem();
        }

        return $this->filesystem;
    }

    /**
     * TODO: Only needed until we can use an in memory filesystem for tests.
     *
     * @see https://github.com/composer/composer/pull/12396
     */
    protected function createTestsTempDir(): void
    {

        $this->testsWorkingDir = FileSystem::normalizeDirSeparator(
            sprintf('%s/%s', sys_get_temp_dir(), uniqid('strausstestdir'))
        );

        if ('Darwin' === PHP_OS) {
            $this->testsWorkingDir = '/private' . $this->testsWorkingDir;
        }

        // If we're running the tests in PhpStorm, set the temp directory to a project subdirectory, so when
        // we set breakpoints, we can easily browse the files.
        if ($this->isPhpStormRunning()) {
            $this->testsWorkingDir = getcwd() . '/teststempdir/' . substr(uniqid(), 4);
        }
    }

    /**
     * TODO: Only needed until we can use an in memory filesystem for tests.
     *
     * @see https://github.com/composer/composer/pull/12396
     */
    protected function getTestsWorkingDirectoryFileSystem(): FileSystem
    {
        set_error_handler(function () {
        }, E_DEPRECATED | E_USER_DEPRECATED);

        if (!isset($this->testsWorkingDir)) {
            $this->createTestsTempDir();
        }

        try {
            $workingDir = $this->testsWorkingDir;

            $localFilesystemAdapter = new LocalFilesystemAdapter(
                $workingDir,
                null,
                LOCK_EX,
                LocalFilesystemAdapter::SKIP_LINKS
            );

            $filesystem = new FileSystem(
                $localFilesystemAdapter,
                [
                        Config::OPTION_DIRECTORY_VISIBILITY => 'public',
                    ],
                Filesystem::makePathNormalizer($workingDir),
                null,
                $workingDir,
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
        if (!isset($this->inMemoryFilesystem)) {
            $this->inMemoryFilesystem = $this->getNewInMemoryFileSystem();
        }

        return $this->inMemoryFilesystem;
    }

    protected function getNewInMemoryFileSystem(): FileSystem
    {
        set_error_handler(function () {
        }, E_DEPRECATED | E_USER_DEPRECATED);

        $normalizer = FileSystem::makePathNormalizer('mem://');

        $inMemoryFilesystemAdapter = new InMemoryFilesystemAdapter();

        $pathPrefixer = new PathPrefixer('mem://', '/');

        $filesystem = new FileSystem(
            $inMemoryFilesystemAdapter,
            [
                Config::OPTION_DIRECTORY_VISIBILITY => 'public',
            ],
            $normalizer,
            $pathPrefixer,
            'mem://'
        );

        /**
         * Register a file stream mem:// to handle file operations by third party libraries.
         *
         * @var FilesystemRegistry $registry
         */
        $registry = ServiceLocator::get(FilesystemRegistry::class);

        if (method_exists($registry, 'has') && $registry->has('mem')) {
            $registry->unregister('mem');
        } else {
            try {
                $registry->get('mem');
                $registry->unregister('mem');
            } catch (Exception $exception) {
                $e = $exception; // suggesting it was not unregistered. but maybe never existed.
            }
        }

        $registry->register('mem', $filesystem);

        restore_error_handler();

        return $filesystem;
    }

    /**
     * Use this method when passing the logger to a class constructor.
     *
     * @return LoggerInterface&Logger
     */
    public function getLogger(): LoggerInterface
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
        $logger->pushProcessor(RelativeFilepathLogProcessor::make($this->getFileSystem()));
        $logger->pushHandler(new PsrHandler($this->getTestLogger()));

        return $logger;
    }

    /**
     * Use this method to retrieve the test logger for assertions.
     */
    public function getTestLogger(): TestLogger
    {
        if (!isset($this->testLogger)) {
            $this->testLogger = new ColorLogger();
        }

        return $this->testLogger;
    }
}
