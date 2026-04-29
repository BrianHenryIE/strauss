<?php
/**
 * "unit" tests should not write to the filesystem.
 */

namespace BrianHenryIE\Strauss;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Helpers\InMemoryFilesystemAdapter;
use BrianHenryIE\Strauss\Helpers\Log\RelativeFilepathLogProcessor;
use BrianHenryIE\Strauss\Helpers\PathPrefixer;
use BrianHenryIE\Strauss\Helpers\ReadOnlyFileSystem;
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

    public bool $allowErrorLogs = false;

    public bool $allowWarningLogs = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = getcwd();

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
    }

    protected function tearDown(): void
    {
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
            if ($this->allowErrorLogs === false) {
                $this->assertFalse($this->getTestLogger()->hasErrorRecords(), "TestLogger::hasErrorRecords()");
            } else {
                $this->assertTrue($this->getTestLogger()->hasErrorRecords(), "Expected TestLogger::hasErrorRecords() but there were none.");
            }
            if ($this->allowWarningLogs === false) {
                $this->assertFalse($this->getTestLogger()->hasWarningRecords(), "TestLogger::hasWarningRecords()");
            } else {
                $this->assertTrue($this->getTestLogger()->hasWarningRecords(), "Expected TestLogger::hasWarningRecords() but there were none.");
            }
        }

        unset($this->logger);
        unset($this->testLogger);
        unset($this->inMemoryFilesystem);
        unset($this->filesystem);
        unset($this->fixturesFilesystem);
    }

    protected function expectWarningLogs()
    {
        $this->allowWarningLogs = true;
    }

    protected function expectErrorLogs()
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
        $string = preg_replace('/^\s*/m', '', $string);
        $string = preg_replace('/\n\s*\n/', "\n", $string);
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
            $readonlyFsAdapter = new ReadOnlyFileSystem(
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
        if (! isset($this->filesystem)) {
            $this->filesystem = $this->getInMemoryFileSystem();
        }

        return $this->filesystem;
    }
//
//    protected function getNewFileSystem(string $workingDir = '/'): FileSystem
//    {
//        $testsWorkingDir = $this->testsWorkingDir ?? getcwd();
//        $normalizer = FileSystem::makePathNormalizer('/');
//
//
//        $localFilesystemAdapter = new LocalFilesystemAdapter(
//            FileSystem::getFsRoot($testsWorkingDir),
//            null,
//            LOCK_EX,
//            LocalFilesystemAdapter::SKIP_LINKS
//        );
//
//        return new FileSystem(
//            $localFilesystemAdapter,
//            [
//                Config::OPTION_DIRECTORY_VISIBILITY => 'public',
//            ],
//            $normalizer,
//            null,
//            $testsWorkingDir
//        );
//    }

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
        $normalizer = FileSystem::makePathNormalizer('mem://');

        $inMemoryFilesystem = new InMemoryFilesystemAdapter();

        $pathPrefixer = new PathPrefixer('mem://', '/');

        $filesystem = new FileSystem(
            $inMemoryFilesystem,
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

        return $filesystem;
    }

    /**
     * Use this method when passing the logger to a class constructor.
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
        $logger->pushProcessor(
            new RelativeFilepathLogProcessor(
                $this->getFileSystem()
            )
        );
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
