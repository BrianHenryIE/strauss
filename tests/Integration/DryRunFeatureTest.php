<?php

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Pipeline\Autoload;
use BrianHenryIE\Strauss\Pipeline\Cleanup;
use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @coversNothing
 */
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

        $this->assertFileExists($this->testsWorkingDir . 'vendor/league/container/src/Container.php');
        $this->assertFileDoesNotExist($this->testsWorkingDir . 'vendor-prefixed/league/container/src/Container.php');
    }

    /**
     * Test CLI argument --dry-run disables changes and outputs to console.
     */
    public function test_cli_argument(): void
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
      "delete_vendor_files": true
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $params = '--dry-run';

        $this->runStrauss($output, $params);

        $this->assertFileExists($this->testsWorkingDir . 'vendor/league/container/src/Container.php');
        $this->assertFileDoesNotExist($this->testsWorkingDir . 'vendor-prefixed/league/container/src/Container.php');
    }

    /**
     * Test CLI argument overrides composer.json config.
     */
    public function test_cli_argument_overrides_composer_json(): void
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

        $params = '--dry-run=false';

        $this->runStrauss($output, $params);

        $this->assertStringNotContainsString('Would copy', $output);

        $this->assertFileExists($this->testsWorkingDir . 'vendor-prefixed/league/container/src/Container.php');
    }

    /**
     *
     *
     * @see Autoload::generateClassmap()
     */
    public function testGenerateAutoload():void
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
      "delete_vendor_packages": true,
      "dry_run": true
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $this->runStrauss($output);

        $this->assertFileDoesNotExist($this->testsWorkingDir . 'vendor-prefixed/autoload.php');
    }

    /**
     * Composer
     *
     * @see Cleanup\InstalledJson::cleanupVendorInstalledJson()
     */
    public function test_composer_files_not_modified(): void
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
      "delete_vendor_packages": true,
      "dry_run": true
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $expected = file_get_contents($this->testsWorkingDir . 'vendor/composer/installed.json');

        $this->runStrauss($output);

        $this->assertEquals(
            $expected,
            file_get_contents(
                $this->testsWorkingDir . 'vendor/composer/installed.json'
            )
        );
    }
}
