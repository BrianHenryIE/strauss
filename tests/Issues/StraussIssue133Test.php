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

        $this->markTestSkippedOnPhpVersionBelow($minimum_php_version);

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

        $exitCode = $this->runStrauss($output);

        $this->assertEquals(0, $exitCode, $output);
    }
}
