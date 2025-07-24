<?php
/**
 * @see https://github.com/BrianHenryIE/strauss/issues/44
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue44Test extends IntegrationTestCase
{

    /**
     * Unprefixed static function call in ternary operation.
     *
     * @author BrianHenryIE
     */
    public function testStaticIsNotPrefixed()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss-issue-44",
  "require": {
    "guzzlehttp/guzzle": "7.4.5"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Strauss\\Issue44\\",
      "classmap_prefix": "Strauss_Issue44_"
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $php_string = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/guzzlehttp/guzzle/src/BodySummarizer.php');

        self::assertStringNotContainsString('? \GuzzleHttp\Psr7\Message::bodySummary($message)', $php_string);

        self::assertStringContainsString('? \Strauss\Issue44\GuzzleHttp\Psr7\Message::bodySummary($message)', $php_string);
    }
}
