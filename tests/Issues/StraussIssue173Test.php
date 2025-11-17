<?php
/**
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/173
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue173Test extends IntegrationTestCase
{
    public function test_issue_173()
    {
        $this->markTestSkippedOnPhpVersionBelow('8.2.0');

        $composerJsonString = <<<'EOD'
{
  "require": {
    "filp/whoops": "2.18.0",
    "guzzlehttp/guzzle": "7.9.3",
    "kucrut/vite-for-wp": "0.10.0",
    "laravel/framework": "11.44.7",
    "livewire/livewire": "3.6.4",
    "spatie/color": "1.8.0",
    "spatie/invade": "2.1.0",
    "spatie/laravel-ignition": "2.9.1",
    "staudenmeir/eloquent-has-many-deep": "1.20.7",
    "vlucas/phpdotenv": "5.6.2",
    "yahnis-elsts/plugin-update-checker": "5.5"
  },
  "minimum-stability": "dev",
  "prefer-stable": true,
  "optimize-autoloader": true,
  "config": {
    "allow-plugins": {
      "composer/installers": true
    },
    "classmap-authoritative": true,
    "optimize-autoloader": true,
    "sort-packages": true
  },
  "extra": {
    "strauss": {
      "target_directory": "vendor",
      "namespace_prefix": "WPSoup\\Vendor\\",
      "constant_prefix": "WPSV_",
      "packages": ["psr/log"],
      "override_autoload": {
        "nesbot/carbon": {
          "autoload": {
            "psr-4": {
              "Carbon\\": "src/Carbon/"
            }
          },
          "classmap": ["lazy"]
        }
      },
      "exclude_from_prefix": {
        "packages": [],
        "namespaces": [],
        "file_patterns": []
      },
      "update_call_sites": true,
      "include_modified_date": false,
      "include_author": false
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');
        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $php_string = file_get_contents($this->testsWorkingDir . 'vendor/psr/log/src/LoggerInterface.php');
        $this->assertStringContainsString("WPSoup\\Vendor\\Psr\\Log\\", $php_string);

        $php_string = file_get_contents($this->testsWorkingDir . 'vendor/composer/installed.json');
        $this->assertStringContainsString("WPSoup\\\\Vendor\\\\Psr\\\\Log\\\\", $php_string);

        $php_string = file_get_contents($this->testsWorkingDir . 'vendor/composer/autoload_psr4.php');
        $this->assertStringContainsString("WPSoup\\\\Vendor\\\\Psr\\\\Log\\\\", $php_string);
    }
}
