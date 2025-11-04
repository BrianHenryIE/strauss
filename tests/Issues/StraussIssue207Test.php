<?php
/**
 * Copy all files in Fremius
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/207
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue207Test extends IntegrationTestCase
{
    public function test_all_files_are_copied()
    {
        $packageComposerJson = <<<'EOD'
{   
	"name": "test/package-with-custom-autoloader",
    "extra": {
        "strauss": {
            "namespace_prefix": "Strauss\\Issue207\\"
        }
    },
    "require": {
        "freemius/wordpress-sdk": "^2.12"
    }
}
EOD;
        file_put_contents($this->testsWorkingDir . '/composer.json', $packageComposerJson);

        chdir($this->testsWorkingDir);
        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

		// Expected anyway.
        $this->assertFileExists($this->testsWorkingDir . '/vendor-prefixed/freemius/wordpress-sdk/start.php');
		// Not part of the autoloader.
        $this->assertFileExists($this->testsWorkingDir . '/vendor-prefixed/freemius/wordpress-sdk/config.php');
    }
}
