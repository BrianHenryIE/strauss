<?php
/**
 * @see https://github.com/BrianHenryIE/strauss/pull/200
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue200Test extends IntegrationTestCase
{
    public function test_does_not_remove_vendor_autoload_dev_entries()
    {

        $composerJsonString = <<<'EOD'
{
  "require": {
    "psr/log": "*"
  },
  "require-dev": {
    "psr/simple-cache": "*"
  },
  "extra": {
    "strauss": {
      "target_directory": "vendor",
      "namespace_prefix": "Company\\Project\\"
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');
        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $php_string = file_get_contents($this->testsWorkingDir . 'vendor/composer/installed.json');
        $this->assertStringContainsString("Company\\\\Project\\\\Psr\\\\Log\\\\", $php_string);
        $this->assertStringContainsString("\"Psr\\\\SimpleCache\\\\", $php_string);

        $php_string = file_get_contents($this->testsWorkingDir . 'vendor/composer/autoload_psr4.php');
        $this->assertStringContainsString("Company\\\\Project\\\\Psr\\\\Log\\\\", $php_string);
        $this->assertStringNotContainsString("'Psr\\\\Log\\\\", $php_string);
        $this->assertStringContainsString("Psr\\\\SimpleCache\\\\", $php_string);
    }
}
