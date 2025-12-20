<?php
/**
 * `$data = @\Aws\parse_ini_file($filename, true, INI_SCANNER_NORMAL);` muted errors not prefixed.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/114
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue114Test extends IntegrationTestCase
{
    public function test_muted_errors(): void
    {

        $composerJsonString = <<<'EOD'
{
  "name": "issue/114",
  "require": {
    "aws/aws-sdk-php": "3.317.0"
  },
  "config": {
    "audit": {
      "ignore": {
        "PKSA-dxyf-6n16-t87m": "We are not running prod"
      }
    }
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\"
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
        $this->assertEquals(0, $exitCode, $output);

        $php_string = file_get_contents($this->testsWorkingDir . '/vendor-prefixed/aws/aws-sdk-php/src/Configuration/ConfigurationResolver.php');

        self::assertStringNotContainsString('@\Aws\parse_ini_file($filename, true, INI_SCANNER_NORMAL);', $php_string);
        self::assertStringContainsString('@\Company\Project\Aws\parse_ini_file($filename, true, INI_SCANNER_NORMAL);', $php_string);
    }
}
