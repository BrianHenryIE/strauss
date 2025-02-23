<?php
/**
 * @see VendorComposerAutoload
 */

namespace BrianHenryIE\Strauss\Autoload;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @coversNothing
 */
class VendorComposerAutoloadFixtureTest extends IntegrationTestCase
{

    public function testHappyPath(): void
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
      "delete_vendor_packages": true
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $composerAutoloadString = file_get_contents($this->testsWorkingDir . 'vendor/autoload.php');

        $this->assertStringContainsString('autoload_aliases.php', $composerAutoloadString);
    }


    public function testInstallNoDev(): void
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
      "delete_vendor_packages": true
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install --no-dev');

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $composerAutoloadString = file_get_contents($this->testsWorkingDir . 'vendor/autoload.php');

        $this->assertStringNotContainsString('autoload_aliases.php', $composerAutoloadString);
    }

    public function testRepeatedlyRunningOnlyAddsOnce(): void
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
      "delete_vendor_packages": true
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output, 'include-autoloader');
        assert(0 === $exitCode, $output);

        $exitCode = $this->runStrauss($output, 'include-autoloader');
        assert(0 === $exitCode, $output);

        $exitCode = $this->runStrauss($output, 'include-autoloader');
        assert(0 === $exitCode, $output);

        $composerAutoloadString = file_get_contents($this->testsWorkingDir . 'vendor/autoload.php');

        $this->assertEquals(
            1,
            substr_count($composerAutoloadString, "require_once __DIR__ . '/../vendor-prefixed/autoload.php'"),
            $composerAutoloadString
        );
    }
}
