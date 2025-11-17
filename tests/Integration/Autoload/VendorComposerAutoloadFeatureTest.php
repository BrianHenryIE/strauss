<?php
/**
 * @see VendorComposerAutoload
 */

namespace BrianHenryIE\Strauss\Autoload;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @coversNothing
 */
class VendorComposerAutoloadFeatureTest extends IntegrationTestCase
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
        $this->assertEquals(0, $exitCode, $output);

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
        $this->assertEquals(0, $exitCode, $output);

        $composerAutoloadString = file_get_contents($this->testsWorkingDir . 'vendor/autoload.php');

        $this->assertStringNotContainsString('autoload_aliases.php', $composerAutoloadString);
    }

    public function testRepeatedlyRunningOnlyAddsAutoloadOnce(): void
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

        $exitCode = $this->runStrauss($output, '--debug');
        $this->assertEquals(0, $exitCode, $output);

        $exitCode = $this->runStrauss($output, 'include-autoloader');
        $this->assertEquals(0, $exitCode, $output);

        $exitCode = $this->runStrauss($output, 'include-autoloader');
        $this->assertEquals(0, $exitCode, $output);

        $exitCode = $this->runStrauss($output, 'include-autoloader');
        $this->assertEquals(0, $exitCode, $output);

        $composerAutoloadString = file_get_contents($this->testsWorkingDir . 'vendor/autoload.php');

        $this->assertEquals(
            1,
            substr_count($composerAutoloadString, "require_once __DIR__ . '/../vendor-prefixed/autoload.php'"),
            $composerAutoloadString
        );
    }

    public function testRepeatedlyRunningOnlyAddsAutoloadAliasesOnce(): void
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
        $this->assertEquals(0, $exitCode, $output);

        $exitCode = $this->runStrauss($output, 'include-autoloader');
        $this->assertEquals(0, $exitCode, $output);

        $exitCode = $this->runStrauss($output, 'include-autoloader');
        $this->assertEquals(0, $exitCode, $output);

        $composerAutoloadString = file_get_contents($this->testsWorkingDir . 'vendor/autoload.php');

        $this->assertEquals(
            1,
            substr_count($composerAutoloadString, "require_once __DIR__ . '/composer/autoload_aliases.php'"),
            $composerAutoloadString
        );
    }

    public function test_does_not_edit_autoloader_namespaces_when_not_deleting_files(): void
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
      "target_directory": "vendor-prefixed",
      "delete_vendor_packages": false
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $composerAutoloadString = file_get_contents($this->testsWorkingDir . 'vendor/composer/autoload_psr4.php');

        $this->assertStringContainsString(
            "'League\\\\Container\\\\",
            $composerAutoloadString
        );

        $this->assertStringNotContainsString(
            "BrianHenryIE\\\\Strauss\\\\League\\\\Container\\\\",
            $composerAutoloadString
        );
    }

    public function test_does_edit_autoloader_namespaces_when_deleting_files(): void
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
      "target_directory": "vendor-prefixed",
      "delete_vendor_packages": true
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $composerAutoloadString = file_get_contents($this->testsWorkingDir . 'vendor/composer/autoload_psr4.php');

        $this->assertStringNotContainsString(
            "'League\\\\Container\\\\",
            $composerAutoloadString
        );

        $this->assertStringNotContainsString(
            "BrianHenryIE\\\\Strauss\\\\League\\\\Container\\\\",
            $composerAutoloadString
        );
    }

    public function test_does_edit_autoloader_namespaces_when_target_is_vendor(): void
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
      "target_directory": "vendor",
      "delete_vendor_packages": false
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $composerAutoloadString = file_get_contents($this->testsWorkingDir . 'vendor/composer/autoload_psr4.php');

        $this->assertStringNotContainsString(
            "'League\\\\Container\\\\",
            $composerAutoloadString
        );

        $this->assertStringContainsString(
            "BrianHenryIE\\\\Strauss\\\\League\\\\Container\\\\",
            $composerAutoloadString
        );
    }
}
