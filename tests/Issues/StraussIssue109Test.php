<?php
/**
 * Defined CLI arguments are breaking the extra.strauss config even when they are not present.
 *
 * @see https://github.com/BrianHenryIE/strauss/pull/109
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue109Test extends IntegrationTestCase
{
    public function test_absent_cli_argument_parsing_does_not_overwrite_config()
    {
        $composerJsonString = <<<'EOD'
{
  "name": "strauss/issue104",
  "require": {
    "psr/log": "1.0.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Strauss\\Issue109\\",
      "delete_vendor_packages": true
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $_SERVER['argv'] = [$this->projectDir . '/bin/strauss'];

        $version = '0.19.1';
        $app = new \BrianHenryIE\Strauss\Console\Application($version);
        $app->setAutoExit(false);
        $result = $app->run();

        $this->assertEquals(0, $result);

        $this->assertFileDoesNotExist($this->testsWorkingDir . 'vendor/psr/log/composer.json');
    }
}
