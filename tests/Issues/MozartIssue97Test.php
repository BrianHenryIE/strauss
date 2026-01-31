<?php
/**
 * The Packagist named crewlabs/unsplash has the composer name unsplash/unsplash.
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * Class MozartIssue97Test
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class MozartIssue97Test extends IntegrationTestCase
{

    /**
     * Issue 97. Package named "crewlabs/unsplash" is downloaded to `vendor/crewlabs/unsplash` but their composer.json
     * has the package name as "unsplash/unsplash".
     *
     * "The "/Users/BrianHenryIE/Sites/mozart-97/vendor/unsplash/unsplash/src" directory does not exist."
     */
    public function testCrewlabsUnsplashSucceeds()
    {

        $composerJsonString = <<<'EOD'
{
	"name": "brianhenryie/mozart-issue-97",
	"require": {
		"crewlabs/unsplash": "3.1.0"
	},
	"extra": {
		"strauss": {
			"namespace_prefix": "BrianHenryIE\\Strauss\\",
			"classmap_prefix": "BrianHenryIE_Strauss_"
		}
	}
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);

        $this->assertEquals(0, $exitCode, $output);
    }
}
