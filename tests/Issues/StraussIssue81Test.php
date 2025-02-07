<?php
/**
 * How to handle prefixed dependencies also used by dev-dependencies
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/81
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;
use Mockery;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue81Test extends IntegrationTestCase
{
    public function test_snake_case_cli_argument_supersedes_configured_option_false_to_true()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "issue/80",
  "require": {
    "psr/log": "1.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\",
      "classmap_prefix": "Issue_81_",
      "delete_vendor_packages": false
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $result = $this->runStrauss($output, '--delete_vendor_packages=true');

        self::assertEquals(0, $result);

        self::assertFileDoesNotExist($this->testsWorkingDir . 'vendor/psr/log/composer.json');
    }

    public function test_snake_case_cli_argument_supersedes_configured_option_false_to_flag()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "issue/80",
  "require": {
    "psr/log": "1.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\",
      "classmap_prefix": "Issue_81_",
      "delete_vendor_packages": false
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $result = $this->runStrauss($output, '--delete_vendor_packages');

        self::assertEquals(0, $result);

        self::assertFileDoesNotExist($this->testsWorkingDir . 'vendor/psr/log/composer.json');
    }

    public function test_snake_case_cli_argument_supersedes_configured_option_true_to_false()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "issue/80",
  "require": {
    "psr/log": "1.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\",
      "classmap_prefix": "Issue_81_",
      "delete_vendor_packages": true
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $result = $this->runStrauss($output, '--delete_vendor_packages=false');

        self::assertEquals(0, $result);

        self::assertFileExists($this->testsWorkingDir . 'vendor/psr/log/composer.json');
    }

    public function test_camel_case_cli_argument_supersedes_configured_option_false_to_true()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "issue/80",
  "require": {
    "psr/log": "1.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\",
      "classmap_prefix": "Issue_81_",
      "delete_vendor_packages": false
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $result = $this->runStrauss($output, '--deleteVendorPackages=true');

        self::assertEquals(0, $result);

        self::assertFileDoesNotExist($this->testsWorkingDir . 'vendor/psr/log/composer.json');
    }
    public function test_camel_case_cli_argument_supersedes_configured_option_false_to_flag()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "issue/80",
  "require": {
    "psr/log": "1.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\",
      "classmap_prefix": "Issue_81_",
      "delete_vendor_packages": false
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $result = $this->runStrauss($output, '--deleteVendorPackages');

        self::assertEquals(0, $result);

        self::assertFileDoesNotExist($this->testsWorkingDir . 'vendor/psr/log/composer.json');
    }

    public function test_camel_case_cli_argument_supersedes_configured_option_true_to_false()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "issue/80",
  "require": {
    "psr/log": "1.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\",
      "classmap_prefix": "Issue_81_",
      "delete_vendor_packages": true
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $result = $this->runStrauss($output, '--deleteVendorPackages=false');

        self::assertEquals(0, $result);

        self::assertFileExists($this->testsWorkingDir . 'vendor/psr/log/composer.json');
    }
}
