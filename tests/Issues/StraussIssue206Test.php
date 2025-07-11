<?php
/**
 * @see https://github.com/BrianHenryIE/strauss/pull/206
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue206Test extends IntegrationTestCase
{
    public function test_cleans_installedjson_autoloadfiles_on_vendor_delete_packages()
    {

        $composerJsonString = <<<'EOD'
{
  "require": {
    "wp-forge/helpers": "2.0.0"
  },
  "extra": {
    "strauss": {
      "target_directory": "vendor-prefixed",
      "namespace_prefix": "Company\\Project\\",
      "delete_vendor_packages": true
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');
        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $installedJsonString = file_get_contents($this->testsWorkingDir . '/vendor/composer/autoload_aliases.php');
        $this->assertStringContainsString('dataGet', $installedJsonString);
    }
}
