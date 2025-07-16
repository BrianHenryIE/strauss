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
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class IntegrationTestCase
 * @package BrianHenryIE\Strauss\Tests\Integration\Util
 * @coversNothing
 */
class IntegrationTestCase extends TestCase
{
    protected string $projectDir;

    protected array $envBeforeTest = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->envBeforeTest = $_ENV;

        $this->projectDir = getcwd();

        $this->logger = new class extends ColorLogger {
            public function debug($message, array $context = array())
            {
                // Mute debug.
            }
        };

        $this->createWorkingDir();

        if (file_exists($this->projectDir . '/strauss.phar')) {
            echo PHP_EOL . 'strauss.phar found' . PHP_EOL;
            ob_flush();
        }
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
                $strauss = new \BrianHenryIE\Strauss\Console\Commands\ReplaceCommand();
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
                    protected function getLogger(InputInterface $input, OutputInterface $output): LoggerInterface
                    {
                        return $this->integrationTestCase->logger;
                    }
                };
        }

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

        $_ENV = $this->envBeforeTest;

        $dir = $this->testsWorkingDir;

        $this->deleteDir($dir);
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
