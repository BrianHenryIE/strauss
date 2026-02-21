<?php
/**
 * [warning] Package directory unexpectedly DOES NOT exist: /path/to/vendor-prefixed/freemius/wordpress-sdk
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/249
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue249Test extends IntegrationTestCase
{

    public function test_return_type_double_prefixed(): void
    {

        $composerJsonString = <<<'EOD'
{   
    "require": {
      "freemius/wordpress-sdk": "^2.13"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "PrintusSmartPrintTiming\\",
            "classmap_prefix": "PrintusSmartPrintTiming_",
            "constant_prefix": "PSPT_",
            "exclude_from_copy": {
                "packages": [
                  "freemius/wordpress-sdk"
                ]
            }
        }
    }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');
        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $this->assertStringNotContainsString('Package directory unexpectedly DOES NOT exist', $output);

        $vendor_prefixed_installedjson_string = $this->getFileSystem()->read($this->testsWorkingDir . 'vendor-prefixed/composer/installed.json');
        $this->assertStringNotContainsString("freemius/wordpress-sdk", $vendor_prefixed_installedjson_string);
        $vendor_installedjson_string = $this->getFileSystem()->read($this->testsWorkingDir . 'vendor/composer/installed.json');
        $this->assertStringContainsString("freemius/wordpress-sdk", $vendor_installedjson_string);
    }
}
