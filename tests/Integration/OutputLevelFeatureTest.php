<?php
/**
 * Test --info, --debug, --quiet, etc.
 */

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @coversNothing
 */
class OutputLevelFeatureTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();

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

    public function test_silent_output_level(): void
    {
        $params = '--silent';

        $this->runStrauss($output, $params);

        $this->assertEmpty($output, $output);
    }

    public function test_normal_output_level(): void
    {
        $this->runStrauss($output);

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
        $params = '--dry-run';

        $this->runStrauss($output, $params);

        $this->assertStringContainsString('[notice]', $output);
        $this->assertStringContainsString('[info]', $output);
        $this->assertStringContainsString('[debug]', $output);
    }
}
