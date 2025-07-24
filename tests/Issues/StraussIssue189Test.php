<?php
/**
 * Fix: namespaces defined in psr4 autoloaders that do not contain classes, i.e. only sub-namespaces have classes
 * defined, were not correctly prefixed, thus not autoloading properly.
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue189Test extends IntegrationTestCase
{
    public function test_prefix_namespace(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "strauss/189",
  "require": {
    "voku/portable-ascii": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Strauss\\Issue189\\",
      "exclude_from_copy": {
        "file_patterns": [ "#voku/portable-ascii/src/voku/helper/data#" ]
      },
      "exclude_from_prefix": {
        "file_patterns": [ "#voku/portable-ascii/src/voku/helper/data#" ]
      }
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);


        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $installedJson = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/composer/installed.json');
        $installedJsonArray = json_decode($installedJson, true);

        $psr4AutoloadKey = $installedJsonArray["packages"][0]["autoload"]["psr-4"];

        $this->assertFalse(isset($psr4AutoloadKey["voku\\"]), 'Namespace not updated; remains voku\\\\');
        $this->assertTrue(isset($psr4AutoloadKey["Strauss\\Issue189\\voku\\"]));
    }
}
