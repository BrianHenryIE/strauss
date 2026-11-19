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
    public function test_issue_173(): void
    {
        // laravel/framework 11 requires PHP 8.2. On PHP 8.6, a dependency's PDF code fails on `MB_ONIGURUMA_VERSION`.
        $this->markTestSkippedOnPhpVersionEqualOrAbove('8.6');
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
              "config": {
                "allow-plugins": {
                  "composer/installers": true
                },
                "audit": {
                  "block-insecure": false
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

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install 2>&1', $installOutput, $installExitCode);
        $this->assertEquals(0, $installExitCode, implode(PHP_EOL, $installOutput));

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        // Prefixing in place a second time must not prefix again.
        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $php_string = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor/psr/log/src/LoggerInterface.php');
        $this->assertStringContainsString('namespace WPSoup\Vendor\Psr\Log;', $php_string);
        $this->assertStringNotContainsString('WPSoup\Vendor\WPSoup', $php_string);

        // The packages not being prefixed use the prefixed psr/log.
        $php_string = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor/laravel/framework/src/Illuminate/Log/Logger.php');
        $this->assertStringContainsString('use WPSoup\Vendor\Psr\Log\LoggerInterface;', $php_string);
        $this->assertStringNotContainsString('WPSoup\Vendor\WPSoup', $php_string);
        $this->assertDoesNotMatchRegularExpression('/(^|[^\\\\A-Za-z])Psr\\\\Log\\\\/m', $php_string);

        // Their files which do not use it are not changed, e.g. Blade templates, which are not PHP.
        $this->assertFalse($this->getTestLogger()->hasWarningThatContains('blade.php'));

        $php_string = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor/composer/installed.json');
        $this->assertStringContainsString("WPSoup\\\\Vendor\\\\Psr\\\\Log\\\\", $php_string);

        $php_string = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor/composer/autoload_psr4.php');
        $this->assertStringContainsString("WPSoup\\\\Vendor\\\\Psr\\\\Log\\\\", $php_string);

        // Loading Laravel's logger loads the prefixed interface it implements.
        $phpCode = 'require "vendor/autoload.php"; new Illuminate\Log\Logger(new Monolog\Logger("x")); echo "OK";';
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($phpCode) . ' 2>&1', $phpOutput, $phpExitCode);
        $this->assertEquals(0, $phpExitCode, implode(PHP_EOL, $phpOutput));
        $this->assertEquals('OK', implode(PHP_EOL, $phpOutput));
    }
}
