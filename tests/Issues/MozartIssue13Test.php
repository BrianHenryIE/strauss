<?php
/**
 * Namespaces in constants not replaced
 * @see https://github.com/coenjacobs/mozart/issues/13
 *
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * Class MozartIssue13Test
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class MozartIssue13Test extends IntegrationTestCase
{

    /**
     *
     * "paypal/rest-api-sdk-php"
     *
     */
    public function testPaypalStringReplacement()
    {
        $this->markTestSkippedOnPhpVersionEqualOrAbove('8.2', 'Fatal error: Allowed memory size of 134217728 bytes exhausted');

        $composerJsonString = <<<'EOD'
{
	"name": "brianhenryie/mozart-issue-13",
	"require": {
		"paypal/rest-api-sdk-php": "*"
	},
	"extra": {
		"strauss": {
			"namespace_prefix": "BrianHenryIE\\Strauss\\",
			"classmap_prefix": "BrianHenryIE_Strauss_",
			"exclude_from_prefix": {
			    "file_patterns": [
			    ]
			}
		}
	}
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $phpString = file_get_contents($this->testsWorkingDir .'vendor-prefixed/paypal/rest-api-sdk-php/lib/PayPal/Log/PayPalLogger.php');

        // Confirm solution is correct.
        self::assertStringContainsString('constant("\\\\BrianHenryIE\\\\Strauss\\\\Psr\\\\Log\\\\LogLevel::$loggingLevel")', $phpString);
    }
}
