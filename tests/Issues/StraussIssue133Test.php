<?php
/**
 * Error when `config.vendor-dir` is multiple directories deep.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/133
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue133Test extends IntegrationTestCase
{
    /**
     * [error] Unable to read file from location: vendor/Repos/rh-admin-utils/keys.dev.pub.
     * file_get_contents(/Users/rah/Documents/Repos/rh-admin-utils/vendor/Repos/rh-admin-utils/keys.dev.pub):
     * Failed to open stream: No such file or directory
     */
    public function test_unable_to_read_file()
    {
        $minimum_php_version = '8.2';

        exec('php -v', $output, $return_var);
        preg_match('/PHP\s([\d\\\.]*)/', $output[0], $php_version_capture);
        $system_php_version = $php_version_capture[1];

        if (!version_compare(phpversion(), $minimum_php_version, '>=') || !version_compare($system_php_version, $minimum_php_version, '>=')) {
            $this->markTestSkipped("Package specified for test is not PHP 8.2 compatible. Running tests under PHP " . phpversion() . ', ' . $system_php_version);
        }

        $composerJsonString = <<<'EOD'
{
  "name": "hirasso/rh-admin-utils",
  "description": "A WordPress utility plugin 🥞",
  "license": "GPL-2.0-or-later",
  "config": {
    "vendor-dir": "./lib/vendor"
  },
  "autoload": {
    "psr-4": {
      "RH\\AdminUtils\\": "lib/rh-admin-utils"
    }
  },
  "type": "wordpress-plugin",
  "minimum-stability": "dev",
  "require": {
    "php": ">=8.2",
    "symfony/var-dumper": "^7.1"
  },
  "require-dev": {
    "squizlabs/php_codesniffer": "*"
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $result = $this->runStrauss();

        $this->assertEquals(0, $result);
    }
}