<?php
/**
 * Root directories can not be deleted
 * @see https://github.com/coenjacobs/mozart/issues/43
 *
 * "File already exists at path: strauss/symfony/event-dispatcher/Tests/EventTest.php"
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * Class MozartIssue43Test
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class MozartIssue43Test extends IntegrationTestCase
{

    /**
     * Issue 43. Needs "aws/aws-sdk-php".
     *
     * League\Flysystem\FileExistsException : File already exists at path:
     * dep_directory/vendor/guzzle/guzzle/src/Guzzle/Cache/Zf1CacheAdapter.php
     */
    public function testAwsSdkSucceeds()
    {
        self::markTestSkipped('Very slow to run');

        $composerJsonString = <<<'EOD'
{
	"name": "brianhenryie/mozart-issue-43",
	"require": {
		"aws/aws-sdk-php": "2.8.31"
	},
	"extra": {
		"strauss": {
			"namespace_prefix": "BrianHenryIE\\Strauss\\",
			"classmap_prefix": "BrianHenryIE_Strauss_",
			"override_autoload": {
				"guzzle/guzzle": {
					"psr-4": {
						"Guzzle": "src/"
					}
				}
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

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        self::assertFileExists($this->testsWorkingDir .'vendor-prefixed/aws/aws-sdk-php/src/AWS/Common/Aws.php');
    }
}
