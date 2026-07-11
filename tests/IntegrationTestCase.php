<?php
/**
 * Creates and deletes a temp directory for tests.
 *
 * Could just system temp directory, but this is useful for setting breakpoints and seeing what has happened.
 */

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\Console\Commands\IncludeAutoloaderCommand;
use BrianHenryIE\Strauss\Console\Commands\ReplaceCommand;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\Helpers\Flysystem\PathPrefixer;
use BrianHenryIE\Strauss\Helpers\Flysystem\ReadOnlyFileSystemAdapter;
use BrianHenryIE\Strauss\Helpers\Flysystem\SymlinkProtectFilesystemAdapter;
use Elazar\Flystream\FilesystemRegistry;
use Elazar\Flystream\ServiceLocator;
use Exception;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\StorageAttributes;
use SplFileInfo;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use const _PHPStan_8c66d8255\__;

/**
 * Class IntegrationTestCase
 * @package BrianHenryIE\Strauss\Tests\Integration\Util
 * @coversNothing
 */
class IntegrationTestCase extends TestCase
{
    use CustomIntegrationTestAssertionsTrait;

    /** No trailing slash */
    protected string $testsWorkingDir;

    /** @var array<string, string> */
    protected array $envBeforeTest = [];

    protected FileSystem $symlinkProtectFilesystem;

    protected FileSystem $readOnlyFileSystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envBeforeTest = $_ENV;

        set_error_handler(function () {
        }, E_DEPRECATED | E_USER_DEPRECATED);

        $this->testsWorkingDir = FileSystem::normalizeDirSeparator(
            sprintf('%s/%s', sys_get_temp_dir(), uniqid('strausstestdir'))
        );

        restore_error_handler();

        if ('Darwin' === PHP_OS) {
            $this->testsWorkingDir = '/private' . $this->testsWorkingDir;
        }

        // If we're running the tests in PhpStorm, set the temp directory to a project subdirectory, so when
        // we set breakpoints, we can easily browse the files.
        if ($this->isPhpStormRunning()) {
            $this->testsWorkingDir = getcwd() . '/teststempdir/' . substr(uniqid(), 4);
        } elseif (file_exists($this->testsWorkingDir)) {
            $this->deleteDir($this->testsWorkingDir);
        }

        @mkdir($this->testsWorkingDir, 0777, true);

        chdir($this->testsWorkingDir);

        $this->pathNormalizer = Filesystem::makePathNormalizer($this->testsWorkingDir);

        if (file_exists($this->projectDir . '/strauss.phar')) {
            echo PHP_EOL . 'strauss.phar found' . PHP_EOL;
            ob_flush();
        }
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

    protected function isTestingPhar(): bool
    {
        return file_exists($this->projectDir . '/strauss.phar');
    }

    protected function runStrauss(?string &$allOutput = null, string $params = '', string $env = ''): int
    {
        if ($this->isTestingPhar()) {
        /**
         * Let's try enable passing an environmental variable so we can get better logs in GitHub Actions.
         *
         * `RENAMESPACER_LOG=debug vendor/bin/strauss` ~~ `strauss --debug` but only in tests.
         */
        // todo: lowercase
        $envLogLevel = trim(getenv('RENAMESPACER_LOG') ?: '', '-');

        if ($this->isPhar()) {
            if (! array_reduce(
                ['--quiet','--warning','--info','--debug','--dry-run'],
                fn(bool $carry, string $level) => $carry || str_contains($params, $level),
                false
            )) {
                // Printing logs is slow.
                $params .= ' --' . (empty($envLogLevel) ? 'info' : $envLogLevel);
            }
            // TODO add xdebug to the command

            // When STRAUSS_FAIL_ON_DEPRECATION is set (in CI, when testing the phar under
            // newer PHP versions), surface PHP deprecation notices and fail the test if the
            // subprocess emitted any. `exec()` captures stdout only, so stderr – where PHP
            // prints `Deprecated:` notices – is redirected to its own file and inspected.
            $failOnDeprecation = (bool) getenv('STRAUSS_FAIL_ON_DEPRECATION');
            $phpFlags = $failOnDeprecation ? '-d error_reporting=E_ALL -d display_errors=stderr ' : '';
            $phpFlags .= ' -d memory_limit=2048M ';
            $stderrFile = tempnam(sys_get_temp_dir(), 'strauss-phar-stderr');

            exec($env . ' php ' . $phpFlags . $this->projectDir . '/strauss.phar ' . $params . ' 2>' . escapeshellarg($stderrFile), $output, $return_var);
            $allOutput = implode(PHP_EOL, $output);
            echo $allOutput;

            $stderr = (string) file_get_contents($stderrFile);
            @unlink($stderrFile);
            if ($stderr !== '') {
                echo $stderr;
            }

            // strauss legitimately logs to stderr, so only fail on PHP deprecation notices.
            if ($failOnDeprecation && preg_match('/(?:PHP )?Deprecated:/', $stderr)) {
                $this->fail('strauss.phar emitted a PHP deprecation notice:' . PHP_EOL . $stderr);
            }

            return $return_var;
        }

        $paramsSplit = explode(' ', trim($params));

        switch ($paramsSplit[0]) {
            case 'include-autoloader':
                $strauss = new IncludeAutoloaderCommand();
                unset($paramsSplit[0]);
                break;
            case 'replace':
                $strauss = new ReplaceCommand();
                unset($paramsSplit[0]);
                break;
            default:
                $strauss = new class() extends  DependenciesCommand {
                    public Logger $monologLogger;
                    protected function getMonologLogger(InputInterface $input, OutputInterface $output): Logger
                    {
                        return $this->monologLogger;
                    }
                };
                $strauss->monologLogger = $this->getLogger();
        }

        $output = new BufferedOutput();

        foreach (array_filter(explode(' ', $env)) as $pair) {
            $kv = explode('=', $pair);
            $_ENV[trim($kv[0])] = trim($kv[1]);
        }

        $argv = array_merge(['strauss'], array_filter($paramsSplit));

        if (!empty($envLogLevel)) {
            $argv[] = '--' . strtolower(trim($envLogLevel, '-'));
        }

        $inputInterface = new ArgvInput($argv);

        // Real invocations run inside an Application, which supplies Symfony's global options
        // (e.g. the `--silent` option added in symfony/console 7.2). Attach one so in-process
        // test runs mirror the CLI and can bind those options.
        $strauss->setApplication(new Application());

        $result = $strauss->run($inputInterface, $output);

        $allOutput = $output->fetch();

        return $result;
    }

    /**
     * Delete $this->testsWorkingDir after each test.
     *
     * @see https://stackoverflow.com/questions/3349753/delete-directory-with-files-in-it
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $_ENV = $this->envBeforeTest;

        $dir = $this->testsWorkingDir;

        try {
            $this->deleteDir($dir);
        } catch (Exception $exception) {
            // Not ideal, but not important enough to fail hard.
        }

        // Hmmm... `mem` also needs to be unique to the tests run.
        try {
            /** @var FilesystemRegistry $registry */
            $registry = ServiceLocator::get(FilesystemRegistry::class);
            $registry->unregister('mem');
        } catch (Exception $e) {
        }

        unset($this->localFileSystem);
        unset($this->symlinkProtectFilesystem);
        unset($this->readOnlyFileSystem);
    }

    /**
     * @throws FilesystemException
     */
    protected function deleteDir(string $directoryPath): void
    {
        if (!file_exists($directoryPath)) {
            return;
        }
        $filesystem = $this->getFileSystem();

        $symfonyFilesystem = new \Symfony\Component\Filesystem\Filesystem();
        $isSymlink = function ($file) use ($symfonyFilesystem) {
            return ! is_null($symfonyFilesystem->readlink($file));
        };

        /**
         * Delete symlinks first.
         *
         * @see https://github.com/thephpleague/flysystem/issues/1560
         */
        $finder = new Finder();
        $finder->in($directoryPath);
        if ($finder->hasResults()) {

            /** @var SplFileInfo[] $files */
            $files = iterator_to_array($finder->getIterator());
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

        if (!is_dir($directoryPath)) {
            return;
        }

        if (!$filesystem->directoryExists($directoryPath)) {
            return;
        }

        $filesystem->deleteDirectory($directoryPath);
    }

    /**
     * E.g. ", its parent directory does not exist".
     * E.g. ", its parent directory contains: file1.php, file2.php, file3.php +6".
     *
     * @param string $path
     * @param FileSystem $filesystem
     *
     * @throws FilesystemException
     */
    protected function getParentDirectoryAssertFailureMessagePart(string $path, FileSystem $filesystem): string
    {
        $append = '';
        $parentDir = dirname($path);
        if (! $filesystem->directoryExists($parentDir)) {
            $append .= ', its parent directory does not exist';
        } else {
            $parentDirList        = $filesystem->listContents($parentDir)->toArray();
            $parentDirListStrings = array_map(
                fn(StorageAttributes $dirEntry) => basename($dirEntry->path()) . ( $dirEntry->type() === 'dir' ? '/' : '' ),
                $parentDirList
            );
            $append               .= ', its parent directory contains: ' . implode(', ', array_slice($parentDirListStrings, 0, 3));
            if (count($parentDirList) > 3) {
                $append .= ' +' . ( count($parentDirList) - 3 );
            }
        }
        return $append;
    }

    protected FileSystem $localFileSystem;

    /**
     * Integration tests' FileSystem use LocalFilesystemAdapter.
     * It is for the /tmp/ working directory, not the project directory.
     */
    protected function getFileSystem(): Filesystem
    {
        if (! isset($this->localFileSystem)) {
            set_error_handler(function () {
            }, E_DEPRECATED | E_USER_DEPRECATED);

            $localFsLocation = FileSystem::getFsRoot($this->testsWorkingDir);
            $pathNormalizer  = Filesystem::makePathNormalizer($this->testsWorkingDir);
            $pathPrefixer    = new PathPrefixer($localFsLocation, DIRECTORY_SEPARATOR);

            $localFileSystemAdapter = new LocalfilesystemAdapter(
                $localFsLocation,
                null,
                LOCK_EX,
                LocalfilesystemAdapter::SKIP_LINKS
            );
            $this->localFileSystem = new class (
                $localFileSystemAdapter,
                [],
                $pathNormalizer,
                $pathPrefixer,
                $localFsLocation,
                $this->testsWorkingDir
            )
            extends FileSystem {
                /** @var array<int, array{location: string, deep: bool}> */
                public array $listContentsCalls = [];
                public function listContents(string $location, bool $deep = self::LIST_SHALLOW): \League\Flysystem\DirectoryListing
                {
                    $this->listContentsCalls[] = ['location' => $location, 'deep' => $deep];

                    return parent::listContents($location, $deep);
                }
            };

            restore_error_handler();
        }
        return $this->localFileSystem;
    }

    protected function getSymlinkProtectFilesystem(): FileSystem
    {
        if (isset($this->symlinkProtectFilesystem)) {
            return $this->symlinkProtectFilesystem;
        }

        $localFilesystemLocation = FileSystem::getFsRoot($this->testsWorkingDir);

        $pathPrefixer = new PathPrefixer($localFilesystemLocation, DIRECTORY_SEPARATOR);

        $symlinkProtectFilesystemAdapter = new SymlinkProtectFilesystemAdapter(
            $localFilesystemLocation,
            FileSystem::makePathNormalizer($this->testsWorkingDir),
            $pathPrefixer,
            $this->getTestLogger()
        );

        $this->symlinkProtectFilesystem = new FileSystem(
            $symlinkProtectFilesystemAdapter,
            [
                Config::OPTION_DIRECTORY_VISIBILITY => 'public',
            ],
            null,
            $pathPrefixer
        );

        return $this->symlinkProtectFilesystem;
    }


    public function getReadOnlyFileSystem(?FilesystemAdapter $protectedFilesystemAdapter = null): FileSystem
    {
        if (isset($this->readOnlyFileSystem)) {
            return $this->readOnlyFileSystem;
        }

        if (is_null($protectedFilesystemAdapter)) {
            $protectedFilesystem = isset($this->testsWorkingDir)
                ? $this->getSymlinkProtectFilesystem()
                : $this->getNewInMemoryFileSystem();
            $protectedFilesystemAdapter = $protectedFilesystem->getAdapter();
        }

        $normalizer = FileSystem::makePathNormalizer($this->testsWorkingDir);

        $pathPrefixer = new PathPrefixer('mem://', '/');

        $this->readOnlyFileSystem =
            new FileSystem(
                new ReadOnlyFileSystemAdapter(
                    $protectedFilesystemAdapter
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
        $registry = ServiceLocator::get(FilesystemRegistry::class);

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
}
