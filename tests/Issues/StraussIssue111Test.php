<?php
/**
 * Should prefix modified classnames in phpdoc
 *
 * @see https://github.com/BrianHenryIE/strauss/pull/111
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue111Test extends IntegrationTestCase
{
    public function test_phpdoc()
    {
        $composerJsonString = <<<'EOD'
{
  "name": "strauss/issue111",
  "require": {
    "stripe/stripe-php": "16.1.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Strauss\\Issue111\\"
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $this->runStrauss();

        $php_string = file_get_contents($this->testsWorkingDir . '/vendor-prefixed/stripe/stripe-php/lib/Payout.php');

        self::assertStringNotContainsString('@throws \Stripe\Exception\ApiErrorException', $php_string);
        self::assertStringContainsString('@throws \Strauss\Issue111\Stripe\Exception\ApiErrorException', $php_string);
    }
}
