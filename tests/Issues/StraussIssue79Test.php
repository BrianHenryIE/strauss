<?php
/**
 * JsonException core PHP class, polyfilled by Symfony, incorrectly replaced
 *
 *
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/79
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue79Test extends IntegrationTestCase
{
    public function test_issue_79()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "issue/79",
  "require": {
    "json-mapper/json-mapper": "2.20.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Issue79\\",
      "classmap_prefix": "BH_Strauss_Issue79_"
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $php_string = file_get_contents($this->testsWorkingDir . '/vendor-prefixed/json-mapper/json-mapper/src/JsonMapper.php');
        self::assertStringNotContainsString('throw new \BH_Strauss_Issue79_JsonException(json_last_error_msg()', $php_string);
        self::assertStringContainsString('throw new \JsonException(json_last_error_msg(), \json_last_error());', $php_string);

        $php_string = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/json-mapper/json-mapper/src/Middleware/AbstractMiddleware.php');
        self::assertStringNotContainsString(' JsonMapper\Middleware;', $php_string);
        self::assertStringContainsString(' BrianHenryIE\Issue79\JsonMapper\Middleware;', $php_string);
    }
}
