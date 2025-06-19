<?php
/**
 * instanceof not prefixed properly.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/83
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;
use Symfony\Component\Console\Input\InputInterface;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue83Test extends IntegrationTestCase
{
    // Excludes everything except aws from copy.
    public function test_issue_83()
    {
        $this->markTestSkippedOnPhpVersionEqualOrAbove('8.2', 'Fatal error: Allowed memory size of 134217728 bytes exhausted');

        $composerJsonString = <<<'EOD'
{
  "name": "issue/83",
  "require": {
    "aws/aws-sdk-php": "3.293.8"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\",
      "exclude_from_copy": {
		  "file_patterns": [
		    "/^((?!aws\\/aws-sdk-php).)*$/"
		  ]
      }
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

        $php_string = file_get_contents($this->testsWorkingDir . '/vendor-prefixed/aws/aws-sdk-php/src/ClientResolver.php');

        self::assertStringNotContainsString('$value instanceof \Aws\EndpointV2\EndpointProviderV2', $php_string);
        self::assertStringContainsString('$value instanceof \Company\Project\Aws\EndpointV2\EndpointProviderV2', $php_string);
    }
}
