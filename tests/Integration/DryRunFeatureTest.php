<?php

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

class DryRunFeatureTest extends IntegrationTestCase
{
    /**
     * Test default config is false.
     *
     * TODO: This should be in a unit test.
     */
    public function test_not_enabled(): void
    {
        $config = new StraussConfig();

        $this->assertFalse($config->isDryRun());
    }

    /**
     * Test using composer.json config disables changes and outputs to console.
     */
    public function test_happy_path(): void
    {
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
      "delete_vendor_files": true,
      "dry_run": true
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $this->runStrauss($output);

        $this->assertStringContainsString('Would copy', $output);

        $this->assertFileExists($this->testsWorkingDir . 'vendor/league/container/src/Container.php');
        $this->assertFileDoesNotExist($this->testsWorkingDir . 'vendor-prefixed/league/container/src/Container.php');
    }

    /**
     * Test CLI argument --dry-run disables changes and outputs to console.
     */
    public function test_cli_argument(): void
    {
    }

    /**
     * Test CLI argument overrides composer.json config.
     */
    public function test_cli_argument_overrides_composer_json(): void
    {
    }
}
