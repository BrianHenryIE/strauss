<?php

namespace BrianHenryIE\Strauss;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Helpers\Log\RelativeFilepathLogProcessor;
use BrianHenryIE\Strauss\Helpers\PathPrefixer;
use BrianHenryIE\Strauss\Helpers\ReadOnlyFileSystem;
use BrianHenryIE\Strauss\Helpers\SymlinkProtectFilesystemAdapter;
use Elazar\Flystream\FilesystemRegistry;
use Elazar\Flystream\StripProtocolPathNormalizer;
use Exception;
use League\Flysystem\Config;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PathNormalizer;
use League\Flysystem\WhitespacePathNormalizer;
use Mockery;
use Monolog\Handler\PsrHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;
use Psr\Log\Test\TestLogger;
use Symfony\Component\Finder\Finder;

class TestCase extends \PHPUnit\Framework\TestCase
{
    use CustomAssertionsTrait;

    /**
     * The logger used by the objects.
     */
    public ?LoggerInterface $logger;

    /**
     * The output logger.
     */
    protected ?TestLogger $testLogger;

    protected FileSystem $inMemoryFilesystem;

    protected string $testsWorkingDir;

    protected PathNormalizer $pathNormalizer;

    protected FileSystem $symlinkProtectFilesystem;

    protected FileSystem $readOnlyFileSystem;

    protected function setUp(): void
    {
        parent::setUp();

        /**
         * We need to register the mem stream wrapper before the static methods in Composer are called.
         *
         * @see \Composer\Util\Filesystem::$streamWrappersRegex
         * @see \Composer\Util\Filesystem::isStreamWrapperPath()
         */
        if (!in_array('mem', stream_get_wrappers())) {
            stream_wrapper_register('mem', \stdClass::class);
            \Composer\Util\Filesystem::isStreamWrapperPath('mem://');
            stream_wrapper_unregister('mem');
        }

        $this->pathNormalizer = new StripProtocolPathNormalizer(['mem'], new WhitespacePathNormalizer());
    }

    protected function createWorkingDir(): void
    {
        $this->testsWorkingDir = sprintf('%s/%s/', sys_get_temp_dir(), uniqid('strausstestdir'));

        if ('Darwin' === PHP_OS) {
            $this->testsWorkingDir = '/private' . $this->testsWorkingDir;
        }

        // If we're running the tests in PhpStorm, set the temp directory to a project subdirectory, so when
        // we set breakpoints, we can easily browse the files.
        if ($this->isPhpStormRunning()) {
            $this->testsWorkingDir = getcwd() . '/teststempdir/';
        }

        if (file_exists($this->testsWorkingDir)) {
            $this->deleteDir($this->testsWorkingDir);
        }

        @mkdir($this->testsWorkingDir);
    }

    protected function deleteDir($dir)
    {
        if (!file_exists($dir)) {
            return;
        }
        $filesystem = new Filesystem(
            new LocalFilesystemAdapter('/')
        );

        $symfonyFilesystem = new \Symfony\Component\Filesystem\Filesystem();
        $isSymlink = function ($file) use ($symfonyFilesystem) {
            return !is_null($symfonyFilesystem->readlink($file));
        };

        /**
         * Delete symlinks first.
         *
         * @see https://github.com/thephpleague/flysystem/issues/1560
         */
        $finder = new Finder();
        $finder->in($dir);
        if ($finder->hasResults()) {

            /** @var \SplFileInfo[] $files */
            $files = iterator_to_array($finder->getIterator());
            /** @var \SplFileInfo[] $links */
            $links = array_filter(
                $files,
                function ($file) use ($isSymlink) {
                    return $isSymlink($file->getPath());
                }
            );

            // Sort by longest filename first.
            uasort($links, function ($a, $b) {
                return strlen($b->getPath()) <=> strlen($a->getPath());
            });

            foreach ($links as $link) {
                $linkPath = "{$link->getPath()}/{$link->getFilename()}";
                unlink($linkPath);
                if (is_readable($linkPath)) {
                    rmdir($linkPath);
                }
            }
        }

        if (!is_dir($dir)) {
            return;
        }

        $filesystem->deleteDirectory($dir);
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
            $localFilesystemAdapter,
            [
                Config::OPTION_DIRECTORY_VISIBILITY => 'public',
            ],
            $normalizer,
            null,
            $this->testsWorkingDir ?? getcwd()
        );
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

        $inMemoryFilesystem = new \BrianHenryIE\Strauss\Helpers\InMemoryFilesystemAdapter();

        $normalizer = new WhitespacePathNormalizer();
        $normalizer = new StripProtocolPathNormalizer(['mem'], $normalizer);

        $pathPrefixer = new PathPrefixer('mem://', '/');

        $filesystem = new Filesystem(
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
        $registry = \Elazar\Flystream\ServiceLocator::get(\Elazar\Flystream\FilesystemRegistry::class);
        $registry->register('mem', $filesystem);

        return $filesystem;
    }

    public function getReadOnlyFileSystem(?FileSystem $filesystem = null)
    {
        if (isset($this->readOnlyFileSystem)) {
            return $this->readOnlyFileSystem;
        }

        if (is_null($filesystem)) {
            $filesystem = $this->getSymlinkProtectFilesystem();
        }

        $normalizer = new WhitespacePathNormalizer();
        $normalizer = new StripProtocolPathNormalizer(['mem'], $normalizer);

        $pathPrefixer = new PathPrefixer('mem://', '/');

        $this->readOnlyFileSystem =
            new FileSystem(
                new ReadOnlyFileSystem(
                    $filesystem->getAdapter(),
                ),
                [],
                $normalizer,
                $pathPrefixer
            );

        /**
         * Register a file stream mem:// to handle file operations by third party libraries.
         *
         * @var FilesystemRegistry $registry
         */
        $registry = \Elazar\Flystream\ServiceLocator::get(\Elazar\Flystream\FilesystemRegistry::class);

        if (method_exists($registry, 'has') && $registry->has('mem')) {
            $registry->unregister('mem');
        } else {
            try {
                $registry->get('mem');
                $registry->unregister('mem');
            } catch (Exception $exception) {
            }
        }

        $registry->register('mem', $this->readOnlyFileSystem);

        return $this->readOnlyFileSystem;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (in_array('mem', stream_get_wrappers())) {
            /** @var FilesystemRegistry $registry */
            $registry = \Elazar\Flystream\ServiceLocator::get(\Elazar\Flystream\FilesystemRegistry::class);
            /**
             * Also runs `stream_wrapper_unregister('mem')`
             */
            $registry->unregister('mem');
        }

        Mockery::close();
    }

    /**
     * Use this method when passing the logger to a class constructor.
     */
    public function getLogger(): LoggerInterface
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
        $logger->pushProcessor(
            new RelativeFilepathLogProcessor(
                $this->getReadOnlyFileSystem(
                    $this->getSymlinkProtectFilesystem()
                )
            )
        );
        $logger->pushHandler(new PsrHandler($this->getTestLogger()));
        return $logger;
    }

    protected function getSymlinkProtectFilesystem(): FileSystem
    {
        if (isset($this->symlinkProtectFilesystem)) {
            return $this->symlinkProtectFilesystem;
        }

        $localFilesystemLocation = PHP_OS_FAMILY === 'Windows' ? substr(getcwd(), 0, 3) : '/';

        $pathPrefixer = new PathPrefixer($localFilesystemLocation, DIRECTORY_SEPARATOR);

        $symlinkProtectFilesystemAdapter = new SymlinkProtectFilesystemAdapter(
            null,
            $pathPrefixer,
            $this->getTestLogger()
        );

        $this->symlinkProtectFilesystem = new Filesystem(
            $symlinkProtectFilesystemAdapter,
            [
                Config::OPTION_DIRECTORY_VISIBILITY => 'public',
            ],
            null,
            $pathPrefixer
        );

        return $this->symlinkProtectFilesystem;
    }

    /**
     * Use this method to retrieve the test logger for assertions.
     */
    protected function getTestLogger(): TestLogger
    {
        if (!isset($this->testLogger)) {
            $this->testLogger = new class() extends ColorLogger {
                public function debug($message, array $context = array())
                {
                    return; // Mute debug messages in tests.
                }
            };
        }

        return $this->testLogger;
    }
}
