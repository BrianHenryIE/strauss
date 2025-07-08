<?php
/**
 * Creates a deletes a temp directory for tests.
 *
 * Could just system temp directory, but this is useful for setting breakpoints and seeing what has happened.
 */

namespace BrianHenryIE\Strauss\Tests\Integration\Util;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\Console\Commands\IncludeAutoloaderCommand;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use Elazar\Flystream\FilesystemRegistry;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\Test\TestLogger;
use Symfony\Component\Console\Input\ArgvInput;
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

    protected $testsWorkingDir;

    public function setUp(): void
    {
        parent::setUp();

        $this->projectDir = getcwd();

        $this->testsWorkingDir = sprintf('%s/%s/', sys_get_temp_dir(), uniqid('strausstestdir'));

        $this->logger = new class extends ColorLogger {
            public function debug($message, array $context = array())
            {
                // Mute debug.
            }
        };

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
            exec($env . ' php ' . $this->projectDir . '/strauss.phar ' . $params, $output, $return_var);
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
                $strauss = new \BrianHenryIE\Strauss\Console\Commands\ReplaceCommand();
                unset($paramsSplit[0]);
                break;
            default:
                $strauss = new DependenciesCommand();
        }

        $this->logger && $strauss->setLogger($this->logger);

        foreach (array_filter(explode(' ', $env)) as $pair) {
            $kv = explode('=', $pair);
            $_ENV[trim($kv[0])] = trim($kv[1]);
        }

        $argv = array_merge(['strauss'], array_filter($paramsSplit));
        $inputInterface = new ArgvInput($argv);

        $bufferedOutput = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);

        $result = $strauss->run($inputInterface, $bufferedOutput);

        $allOutput = $bufferedOutput->fetch();

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

        $dir = $this->testsWorkingDir;

        $this->deleteDir($dir);

        /** @var FilesystemRegistry $registry */
        try {
            $registry = \Elazar\Flystream\ServiceLocator::get(\Elazar\Flystream\FilesystemRegistry::class);
            $registry->unregister('mem');
        } catch (\Exception $e) {
        }
    }

    protected function deleteDir($dir)
    {
        if (!file_exists($dir)) {
            return;
        }
        $filesystem = new Filesystem(
            new \League\Flysystem\Filesystem(
                new LocalFilesystemAdapter('/')
            ),
            $this->testsWorkingDir
        );

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
}
