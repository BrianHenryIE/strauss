<?php
/**
 * Creates a deletes a temp directory for tests.
 *
 * Could just system temp directory, but this is useful for setting breakpoints and seeing what has happened.
 */

namespace BrianHenryIE\Strauss;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\Console\Commands\IncludeAutoloaderCommand;
use BrianHenryIE\Strauss\Console\Commands\ReplaceCommand;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use Elazar\Flystream\FilesystemRegistry;
use Elazar\Flystream\ServiceLocator;
use Exception;
use Psr\Log\LoggerInterface;
use League\Flysystem\StorageAttributes;
use SplFileInfo;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

/**
 * Class IntegrationTestCase
 * @package BrianHenryIE\Strauss\Tests\Integration\Util
 * @coversNothing
 */
class IntegrationTestCase extends TestCase
{
    protected string $projectDir;

    /** No trailing slash */
    protected string $testsWorkingDir;

    protected array $envBeforeTest = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->envBeforeTest = $_ENV;

        $this->projectDir = getcwd();

        $this->testsWorkingDir = FileSystem::normalizeDirSeparator(
            sprintf('%s/%s', sys_get_temp_dir(), uniqid('strausstestdir'))
        );

        $this->logger = new ColorLogger();

        if ('Darwin' === PHP_OS) {
            $this->testsWorkingDir = '/private' . $this->testsWorkingDir;
        }

        // If we're running the tests in PhpStorm, set the temp directory to a project subdirectory, so when
        // we set breakpoints, we can easily browse the files.
        if ($this->isPhpStormRunning()) {
            $this->testsWorkingDir = getcwd() . '/teststempdir';
        }

        if (file_exists($this->testsWorkingDir)) {
            $this->deleteDir($this->testsWorkingDir);
        }

        @mkdir($this->testsWorkingDir);
//        $this->createWorkingDir();

        chdir($this->testsWorkingDir);

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

    protected function runStrauss(?string &$allOutput = null, string $params = '', string $env = ''): int
    {
        if (file_exists($this->projectDir . '/strauss.phar')) {
            // TODO add xdebug to the command
            exec($env . ' php ' . $this->projectDir . '/strauss.phar ' . $params .' 2>&1', $output, $return_var);
            $allOutput = implode(PHP_EOL, $output);
            echo $allOutput;
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
                $strauss = new class($this) extends DependenciesCommand {
                    protected IntegrationTestCase $integrationTestCase;

                    public function __construct(
                        IntegrationTestCase $integrationTestCase,
                        ?string $name = null
                    ) {
                        $this->integrationTestCase = $integrationTestCase;
                        parent::__construct($name);
                    }

                    protected function getIOLogger(InputInterface $input, OutputInterface $output): LoggerInterface
                    {
                        return method_exists($this->integrationTestCase, 'getIOLogger')
                            ? $this->integrationTestCase->getIOLogger($input, $output)
                            : $this->integrationTestCase->getLogger();
                    }

                    protected function getReadOnlyFileSystem(FileSystem $filesystem): FileSystem
                    {
                        return $this->integrationTestCase->getReadOnlyFileSystem($filesystem);
                    }
                };
        }

        $strauss->setLogger($this->getLogger());

        // TODO: I don't know what I did to break the previous colorlogger output so this is just a crutch.
        $output = new class() extends BufferedOutput {
            protected function doWrite(string $message, bool $newline)
            {
                parent::doWrite($message, $newline);
                echo $message . PHP_EOL;
            }
        };

        foreach (array_filter(explode(' ', $env)) as $pair) {
            $kv = explode('=', $pair);
            $_ENV[trim($kv[0])] = trim($kv[1]);
        }

        $argv = array_merge(['strauss'], array_filter($paramsSplit));

        /**
         * Let's try enable passing an environmental variable so we can get better logs in GitHub Actions.
         *
         * `RENAMESPACER_LOG=debug vendor/bin/strauss` ~~ `strauss --debug` but only in tests.
         */
        $env_log_level = getenv('RENAMESPACER_LOG');
        if (!empty($env_log_level)) {
            $argv[] = '--' . strtolower(trim($env_log_level, '-'));
        }

        $inputInterface = new ArgvInput($argv);

        $result = $strauss->run($inputInterface, $output);

        $allOutput = $output->fetch();

        return $result;
    }

    /**
     * Delete $this->testsWorkingDir after each test.
     *
     * @see https://stackoverflow.com/questions/3349753/delete-directory-with-files-in-it
     */
    public function tearDown(): void
    {
        parent::tearDown();

        $_ENV = $this->envBeforeTest;

        $dir = $this->testsWorkingDir;

        try {
            $this->deleteDir($dir);
        } catch (Exception $exception) {
            // Not ideal, but not important enough to fail hard.
        }

        /** @var FilesystemRegistry $registry */
        try {
            $registry = ServiceLocator::get(FilesystemRegistry::class);
            $registry->unregister('mem');
        } catch (Exception $e) {
        }
    }

    protected function deleteDir($dir)
    {
        if (!file_exists($dir)) {
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
        $finder->in($dir);
        if ($finder->hasResults()) {

            /** @var SplFileInfo[] $files */
            $files = iterator_to_array($finder->getIterator());
            /** @var SplFileInfo[] $links */
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

        if (!$filesystem->directoryExists($dir)) {
            return;
        }

        $filesystem->deleteDirectory($dir);
    }

    public function markTestSkippedOnPhpVersionBelow(string $php_version, string $message = '')
    {
        $this->markTestSkippedOnPhpVersion($php_version, '<', $message);
    }
    public function markTestSkippedOnPhpVersionEqualOrBelow(string $php_version, string $message = '')
    {
        $this->markTestSkippedOnPhpVersion($php_version, '<=', $message);
    }
    public function markTestSkippedOnPhpVersionAbove(string $php_version, string $message = '')
    {
        $this->markTestSkippedOnPhpVersion($php_version, '>', $message);
    }
    public function markTestSkippedOnPhpVersionEqualOrAbove(string $php_version, string $message = '')
    {
        $this->markTestSkippedOnPhpVersion($php_version, '>=', $message);
    }

    /**
     * Checks both the PHP version the tests are running under and the system PHP version.
     */
    public function markTestSkippedOnPhpVersion(string $php_version, string $operator, string $message = '')
    {
        exec('php -v', $output, $return_var);
        preg_match('/PHP\s([\d\\\.]*)/', $output[0], $php_version_capture);
        $system_php_version = $php_version_capture[1];

        $testPhpVersionConstraintMatch = version_compare(phpversion(), $php_version, $operator);
        $systemPhpVersionConstraintMatch = version_compare($system_php_version, $php_version, $operator);

        if ($testPhpVersionConstraintMatch || $systemPhpVersionConstraintMatch) {
            empty($message)
                ? $this->markTestSkipped("Package specified for test cannot run on PHP $operator $php_version. Running PHPUnit with PHP " . phpversion() . ', on system PHP ' . $system_php_version)
                : $this->markTestSkipped($message);
        }
    }

    protected function assertFileNotExistsInFileSystem(string $filePath, ?FileSystem $filesystem = null, ?string $message = null): void
    {
        $filesystem = $filesystem ?? $this->getFileSystem();
        $result = $filesystem->fileExists($filePath);
        $this->assertFalse(
            $result,
            $message ?? $filePath . ' should not exist.'
        );
    }

    protected function assertFileExistsInFileSystem(string $filePath, ?FileSystem $filesystem = null, ?string $message = null): void
    {
        $filesystem = $filesystem ?? $this->getFileSystem();

        $result = $filesystem->fileExists($filePath);

        $append = $result ? '' : $this->getParentDirectoryAssertFailureMessagePart($filePath, $filesystem);

        $this->assertTrue(
            $result,
            $message ?? $filePath . ' should exist' . $append
        );
    }

    protected function assertDirectoryNotExistsInFileSystem(string $directoryPath, ?FileSystem $filesystem = null, ?string $message = null): void
    {
        $filesystem = $filesystem ?? $this->getFileSystem();
        $result = $filesystem->directoryExists($directoryPath);
        $this->assertFalse(
            $result,
            $message ?? $directoryPath . ' should not exist.'
        );
    }

    protected function assertDirectoryExistsInFileSystem(string $directoryPath, ?FileSystem $filesystem = null, ?string $message = null): void
    {
        $filesystem = $filesystem ?? $this->getFileSystem();

        $result = $filesystem->directoryExists($directoryPath);

        $append = $result ? '' : $this->getParentDirectoryAssertFailureMessagePart($directoryPath, $filesystem);

        $this->assertTrue(
            $result,
            $message ?? $directoryPath . ' should exist' . $append
        );
    }

    /**
     * E.g. ", its parent directory does not exist".
     * E.g. ", its parent directory contains: file1.php, file2.php, file3.php +6".
     *
     * @param string $path
     * @param FileSystem $filesystem
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
}
