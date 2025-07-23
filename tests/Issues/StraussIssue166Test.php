<?php
/**
 * namespaced trait name
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/166
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue166Test extends IntegrationTestCase
{
    public function test_namespaced_trait()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "issue/119",
  "require": {
    "stripe/stripe-php":"v17.2.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\",
      "classmap_prefix": "Company_Project_"
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $php_string = file_get_contents($this->testsWorkingDir . '/vendor-prefixed/stripe/stripe-php/lib/Billing/CreditGrant.php');

        $this->assertStringNotContainsString('use \\\\Company\\\\Project\\\\Stripe\\ApiOperations\\Update;', $php_string);

        $this->assertStringContainsString('use \\Company\\Project\\Stripe\\ApiOperations\\Update;', $php_string);
    }
}
