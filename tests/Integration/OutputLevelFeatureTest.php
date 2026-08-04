<?php
/**
 * Test --info, --debug, --quiet, etc.
 */

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\IntegrationTestCase;

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
      "namespace_prefix": "BrianHenryIE\\TestStrauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_",
      "delete_vendor_files": true
    }
  }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');
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

        if ($this->isTestingPhar()) {
            $this->assertStringContainsString('[notice]', $output);
            $this->assertStringNotContainsString('[info]', $output);
            $this->assertStringNotContainsString('[debug]', $output);
        } else {
            $this->assertTrue($this->getTestLogger()->hasNoticeRecords());

            // When re-running failed tests in GitHub Actions, we set `RENAMESPACER_LOG=debug` for all tests.
            if (getenv('RENAMESPACER_LOG') !== 'debug' && getenv('RENAMESPACER_LOG') !== 'info') {
                $this->assertStringNotContainsString('[info]', $output);
                $this->assertStringNotContainsString('[debug]', $output);
            }
        }
    }

    public function test_info_output_level(): void
    {
        $params = '--info';

        $this->runStrauss($output, $params);

        if ($this->isTestingPhar()) {
            $this->assertStringContainsString('[notice]', $output);
            $this->assertStringContainsString('[info]', $output);
            $this->assertStringNotContainsString('[debug]', $output);
        } else {
            $this->assertTrue($this->getTestLogger()->hasNoticeRecords());
            $this->assertTrue($this->getTestLogger()->hasInfoRecords());

            // When re-running failed tests in GitHub Actions, we set `RENAMESPACER_LOG=debug` for all tests.
            if (getenv('RENAMESPACER_LOG') !== 'debug') {
                $this->assertStringNotContainsString('[debug]', $output);
            }
        }
    }

    public function test_debug_output_level(): void
    {
        $params = '--debug';

        $this->runStrauss($output, $params);

        if ($this->isTestingPhar()) {
            $this->assertStringContainsString('[notice]', $output);
            $this->assertStringContainsString('[info]', $output);
            $this->assertStringContainsString('[debug]', $output);
        } else {
            $this->assertTrue($this->getTestLogger()->hasNoticeRecords());
            $this->assertTrue($this->getTestLogger()->hasInfoRecords());
            $this->assertTrue($this->getTestLogger()->hasDebugRecords());
        }
    }

    public function test_dry_run_output_level(): void
    {
        $params = '--dry-run';

        $this->runStrauss($output, $params);

        if ($this->isTestingPhar()) {
            $this->assertStringContainsString('[notice]', $output);
            $this->assertStringContainsString('[info]', $output);
            $this->assertStringContainsString('[debug]', $output);
        } else {
            $this->assertTrue($this->getTestLogger()->hasNoticeRecords());
            $this->assertTrue($this->getTestLogger()->hasInfoRecords());
            $this->assertTrue($this->getTestLogger()->hasDebugRecords());
        }
    }
}
