<?php
/**
 * @see https://github.com/BrianHenryIE/strauss/pull/213
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue213Test extends IntegrationTestCase
{
    /**
     * Ensure the autoload key is not inadvertently removed.
     */
    public function test_cleans_installedjson_autoloadfiles_on_vendor_delete_packages_with_unusual_path(): void
    {

        $composerJsonString = <<<'EOD'
{
  "require": {
    "wp-forge/helpers": "2.0.0"
  },
  "require-dev": {
    "wp-forge/wp-loop": "1.0.0"
  },
  "extra": {
    "strauss": {
      "target_directory": "lib/packages",
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
        $this->assertEquals(0, $exitCode, $output);

        $vendorPrefixedInstalledJsonString = file_get_contents($this->testsWorkingDir . 'lib/packages/composer/installed.json');

        $this->assertStringContainsString('"install-path": "../wp-forge/helpers"', $vendorPrefixedInstalledJsonString);

        $this->assertStringContainsString('"Company\\\\Project\\\\WP_Forge\\\\Helpers\\\\": "includes"', $vendorPrefixedInstalledJsonString);
    }
}
