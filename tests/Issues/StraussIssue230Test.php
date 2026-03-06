<?php
/**
 *  willdurand/geocoder:4.6.0
 *
 * `vendor-prefixed/willdurand/geocoder/StatefulGeocoder.php`
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/230
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue230Test extends IntegrationTestCase
{

    public function test_return_type_double_prefixed(): void
    {

        $composerJsonString = <<<'EOD'
{
  "name": "strauss/issue230",
  "require": {
    "willdurand/geocoder":"4.6.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\"
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install', $composerInstallOutput, $composerInstallExitCode);
        $this->assertEquals(0, $composerInstallExitCode, implode(PHP_EOL, $composerInstallOutput));

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $php_string = $this->getFileSystem()->read($this->testsWorkingDir . 'vendor-prefixed/willdurand/geocoder/StatefulGeocoder.php');
        $this->assertStringNotContainsString("final class StatefulGeocoder implements BrianHenryIE\\Geocoder", $php_string);
        $this->assertStringContainsString("final class StatefulGeocoder implements Geocoder", $php_string);
    }
}
