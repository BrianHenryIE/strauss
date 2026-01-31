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
  "require-dev": {
    "wp-forge/wp-loop": "1.0.0"
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

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');
        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $installedJsonString = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor/composer/autoload_aliases.php');
        $this->assertStringContainsString('dataGet', $installedJsonString);

        $vendorPrefixedAutoloadFilesString = $this->getFileSystem()->read($this->testsWorkingDir . 'vendor-prefixed/composer/autoload_files.php');
        $this->assertStringContainsString("/wp-forge/helpers/includes/functions.php", $vendorPrefixedAutoloadFilesString);

        $installedJsonString = $this->getFileSystem()->read($this->testsWorkingDir . 'vendor/composer/installed.json');
        $this->assertStringNotContainsString("\"WP_Forge\\Helpers", $installedJsonString);

        $vendorPrefixedInstalledJsonString = $this->getFileSystem()->read($this->testsWorkingDir . 'vendor-prefixed/composer/installed.json');
        $this->assertStringContainsString("Company\\\\Project\\\\WP_Forge\\\\Helpers\\\\", $vendorPrefixedInstalledJsonString);

        $this->assertStringContainsString('"install-path": "../wp-forge/helpers"', $vendorPrefixedInstalledJsonString);

        $vendorAutoloadFilesString = $this->getFileSystem()->read($this->testsWorkingDir . 'vendor/composer/autoload_files.php');
        $this->assertStringNotContainsString("/wp-forge/helpers/includes/functions.php", $vendorAutoloadFilesString);
    }
}
