<?php
/**
 * Should prefix modified classnames in phpdoc
 *
 * @see https://github.com/BrianHenryIE/strauss/pull/111
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

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

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $php_string = $this->getFileSystem()->read($this->testsWorkingDir . 'vendor-prefixed/stripe/stripe-php/lib/Payout.php');

        self::assertStringNotContainsString('@return \Stripe\Collection<\Stripe\Payout> of ApiResources', $php_string);
        self::assertStringContainsString('@return \Strauss\Issue111\Stripe\Collection<\Strauss\Issue111\Stripe\Payout> of ApiResources', $php_string);
    }
}
