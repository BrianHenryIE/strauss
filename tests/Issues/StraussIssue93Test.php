<?php
/**
 * Cleanup vendor/composer/installed.json after delete-vendor-directories
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/93#issuecomment-2043919370
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue93Test extends IntegrationTestCase
{
    public function test_removes_entries_from_installed_json()
    {
        $composerJsonString = <<<'EOD'
{
  "name": "strauss/issue93",
  "require": {
    "symfony/polyfill-php80": "v1.29.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Strauss\\Issue93\\",
      "delete_vendor_files": true
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        exec('composer dump-autoload', $output, $result_code);

        self::assertEquals(0, $result_code);
    }
}
