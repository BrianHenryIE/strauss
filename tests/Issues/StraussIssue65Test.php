<?php
/**
 * @see https://github.com/BrianHenryIE/strauss/issues/65
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue65Test extends IntegrationTestCase
{

    /**
     * This passes on 8.4 but fails on 7.4 with an infinite loop in php-parser.
     */
    public function test_aws_prefixed_functions()
    {
        $this->markTestSkippedOnPhpVersionBelow('8.0');

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss-issue-65-aws-prefixed-functions",
  "require": {
    "aws/aws-sdk-php": "3.268.17"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Issue65\\",
      "classmap_prefix": "BH_Strauss_Issue65_"
    },
    "aws/aws-sdk-php": [
        "S3"
    ]
  },
  "scripts": {
    "pre-autoload-dump": "Aws\\Script\\Composer\\Composer::removeUnusedServices"
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        // vendor/aws/aws-sdk-php/src/Endpoint/UseDualstackEndpoint/Configuration.php

        $php_string = file_get_contents($this->testsWorkingDir .'vendor-prefixed/aws/aws-sdk-php/src/Endpoint/UseDualstackEndpoint/Configuration.php');

        self::assertStringNotContainsString('$this->useDualstackEndpoint = Aws\boolean_value($useDualstackEndpoint);', $php_string);
        self::assertStringNotContainsString('$this->useDualstackEndpoint = BrianHenryIE\Issue65\Aws\boolean_value($useDualstackEndpoint);', $php_string);
        self::assertStringContainsString('$this->useDualstackEndpoint = \BrianHenryIE\Issue65\Aws\boolean_value($useDualstackEndpoint);', $php_string);
    }
}
