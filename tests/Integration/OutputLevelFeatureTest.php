<?php
/**
 * Test --info, --debug, --quiet, etc.
 */

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\IntegrationTestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @coversNothing
 */
class OutputLevelFeatureTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->logger = null;

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "league/container": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_",
      "delete_vendor_files": true
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');
    }

    protected bool $isDryRun = false;

    public function getIOLogger(InputInterface $input, OutputInterface $output): LoggerInterface
    {
        $isDryRun = $this->isDryRun;

        // Who would want to dry-run without output?
        if (!$isDryRun && $input->hasOption('silent') && $input->getOption('silent') !== false) {
            return new NullLogger();
        }

        $logLevel = [LogLevel::NOTICE => OutputInterface::VERBOSITY_NORMAL];

        if ($input->hasOption('info') && $input->getOption('info') !== false) {
            $logLevel[LogLevel::INFO]= OutputInterface::VERBOSITY_NORMAL;
        }

        if ($isDryRun || ($input->hasOption('debug') && $input->getOption('debug') !== false)) {
            $logLevel[LogLevel::INFO]= OutputInterface::VERBOSITY_NORMAL;
            $logLevel[LogLevel::DEBUG]= OutputInterface::VERBOSITY_NORMAL;
        }

        return isset($this->logger) && $this->logger instanceof \Psr\Log\Test\TestLogger
            ? $this->logger
            : new ConsoleLogger($output, $logLevel);
    }

    public function test_silent_output_level(): void
    {
        $params = '--silent';

        $this->runStrauss($output, $params);

        $this->assertEmpty($output, $output);
    }

    public function test_normal_output_level(): void
    {
        $exitCode = $this->runStrauss($output);
        assert($exitCode === 0, $output);

        $this->assertStringContainsString('[notice]', $output);
        $this->assertStringNotContainsString('[info]', $output);
        $this->assertStringNotContainsString('[debug]', $output);
    }

    public function test_info_output_level(): void
    {
        $params = '--info';

        $this->runStrauss($output, $params);

        $this->assertStringContainsString('[notice]', $output);
        $this->assertStringContainsString('[info]', $output);
        $this->assertStringNotContainsString('[debug]', $output);
    }

    public function test_debug_output_level(): void
    {
        $params = '--debug';

        $this->runStrauss($output, $params);

        $this->assertStringContainsString('[notice]', $output);
        $this->assertStringContainsString('[info]', $output);
        $this->assertStringContainsString('[debug]', $output);
    }

    public function test_dry_run_output_level(): void
    {
        unset($this->logger);

        $this->isDryRun = true;

        $params = '--dry-run';

        $this->runStrauss($output, $params);

        $this->assertStringContainsString('[notice]', $output);
        $this->assertStringContainsString('[info]', $output);
        $this->assertStringContainsString('[debug]', $output);
    }
}
