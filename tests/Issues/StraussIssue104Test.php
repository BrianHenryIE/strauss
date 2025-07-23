<?php
/**
 * `vendor-prefixed` directory permissions changed after Flysystem update.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/104
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue104Test extends IntegrationTestCase
{
    public function test_correct_directory_permission()
    {
        $composerJsonString = <<<'EOD'
{
  "name": "strauss/issue104",
  "require": {
    "psr/log": "1.0.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Strauss\\Issue104\\"
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $result = substr(sprintf('%o', fileperms($this->testsWorkingDir . 'vendor-prefixed')), -4);

        self::assertEquals('0755', $result);

        $subfolderResult = substr(sprintf('%o', fileperms($this->testsWorkingDir . 'vendor-prefixed/psr')), -4);

        self::assertEquals('0755', $subfolderResult);
    }
}
