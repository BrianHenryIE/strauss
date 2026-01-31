<?php
/**
 * Improper dealing with global namespaces
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/172
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue172Test extends IntegrationTestCase
{
    public function test_issue_172()
    {
        $composerJsonString = <<<'EOD'
{
  "name": "issue/80",
  "require": {
    "guzzlehttp/guzzle": "7.9.3"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\"
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install --no-dev');
        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $php_string = $this->getFileSystem()->read($this->testsWorkingDir . 'vendor-prefixed/guzzlehttp/guzzle/src/Client.php');

        self::assertStringContainsString("class Client implements ClientInterface, \Company\Project\Psr\Http\Client\ClientInterface", $php_string);
    }
}
