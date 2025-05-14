<?php
/**
 * Strauss generated autoload doesn't respect `"platform-check": false`.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/159
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue159Test extends IntegrationTestCase
{
    public function test_autoloader_does_not_include_platform_check()
    {
        $composerJsonString = <<<'EOD'
{
  "name": "strauss/issue159",
  "config": {
		"platform": {
			"php": "7.4.33"
		},
        "platform-check": false
	},
  "require": {
    "php": ">=7.4",
    "psr/log": "^1.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\",
      "classmap_prefix": "Company_Project_"
	}
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install --no-dev');

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $this->assertFileDoesNotExist($this->testsWorkingDir . 'vendor-prefixed/composer/platform_check.php');
    }
}
