<?php

/**
 * When set, and when Strauss is run a second time, `autoloader-suffix` in vendor-prefixed conflicts with vendor.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/362
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue362Test extends IntegrationTestCase
{
    public function test_delete_vendor_packages_and_delete_vendor_files_together(): void
    {
        $this->markTestSkippedOnPhpVersionBelow('8.1');

        $composerJsonString = <<<'EOD'
            {
              "name": "strauss/issue362",
              "require": {
                "psr/log": "^3.0"
              },
              "autoload": {
                "psr-4": { "Example\\Plugin\\": "src/" }
              },
              "config": {
                "autoloader-suffix": "ExamplePlugin"
              }
            }
            EOD;

        mkdir($this->testsWorkingDir . '/src');
        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $unprefixedFilepath = $this->testsWorkingDir . '/vendor/composer/autoload_real.php';
        $php_string = $this->getFileSystem()->read($unprefixedFilepath);
        $this->assertStringContainsString('class ComposerAutoloaderInitExamplePlugin' . PHP_EOL, $php_string);
        $this->assertStringNotContainsString('class ComposerAutoloaderInitExamplePluginPrefixed', $php_string);

        $prefixedFilepath = $this->testsWorkingDir . '/vendor-prefixed/composer/autoload_real.php';
        $php_string = $this->getFileSystem()->read($prefixedFilepath);
        $this->assertStringNotContainsString('class ComposerAutoloaderInitExamplePlugin' . PHP_EOL, $php_string);
        $this->assertStringContainsString('class ComposerAutoloaderInitExamplePluginPrefixed', $php_string);
    }
}
