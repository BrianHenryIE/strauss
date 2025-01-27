<?php
/**
 * Creates a deletes a temp directory for tests.
 *
 * Could just system temp directory, but this is useful for setting breakpoints and seeing what has happened.
 */

namespace BrianHenryIE\Strauss\Tests\Integration\Util;

use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
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

    protected $testsWorkingDir;

    public function setUp(): void
    {
        parent::setUp();

        $this->projectDir = getcwd();

        $this->testsWorkingDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'strausstestdir' . DIRECTORY_SEPARATOR;

        if ('Darwin' === PHP_OS) {
            $this->testsWorkingDir = DIRECTORY_SEPARATOR . 'private' . $this->testsWorkingDir;
        }

        if (file_exists($this->testsWorkingDir)) {
            $this->deleteDir($this->testsWorkingDir);
        }

        @mkdir($this->testsWorkingDir);

        if (file_exists($this->projectDir . '/strauss.phar')) {
            echo "strauss.phar found\n";
            ob_flush();
        }
    }

    protected function runStrauss(?string &$allOutput = null): int
    {
        if (file_exists($this->projectDir . '/strauss.phar')) {
            exec('php ' . $this->projectDir . '/strauss.phar', $output, $return_var);
            $allOutput = implode(PHP_EOL, $output);
            return $return_var;
        }

        $inputInterfaceMock = $this->createMock(InputInterface::class);
        $bufferedOutput = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);

        $strauss = new DependenciesCommand();

        $result = $strauss->run($inputInterfaceMock, $bufferedOutput);

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
    }

    protected function deleteDir($dir)
    {
        if (!file_exists($dir)) {
            return;
        }
        $filesystem = new Filesystem(
            new \League\Flysystem\Filesystem(
                new LocalFilesystemAdapter('/')
            )
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

    /**
     * Checks both the PHP version the tests are running under and the system PHP version.
     */
    public function markTestSkippedOnPhpVersion(string $php_version, string $operator)
    {
        exec('php -v', $output, $return_var);
        preg_match('/PHP\s([\d\\\.]*)/', $output[0], $php_version_capture);
        $system_php_version = $php_version_capture[1];

        if (! version_compare(phpversion(), $php_version, $operator) || ! version_compare($system_php_version, $php_version, $operator)) {
            $this->markTestSkipped("Package specified for test is not PHP 8.2 compatible. Running tests under PHP " . phpversion() . ', ' . $system_php_version);
        }
    }
}
