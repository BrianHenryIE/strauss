<?php
/**
 * AWS not working after Mozart has been ran
 * @see https://github.com/coenjacobs/mozart/issues/62
 *
 * Possibly down to multiple autoload directories in one autoload key. Mozart was only reading the second key from
 * ```
 * "autoload": {
 *  "psr-0": {
 *      "Guzzle": "src/",
 *      "Guzzle\\Tests": "tests/"
 *   }
 * }
 * ```
 * (although arguably, it shouldn't read the second at all).
 *
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * Class MozartIssue62Test
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class MozartIssue62Test extends IntegrationTestCase
{

    /**
     * Just confirms `use Guzzle\Common\Collection;` is prefixed.
     */
    public function testGuzzleNamespaceIsPrefixedInS3Client()
    {
        self::markTestSkipped('Very slow to run.');

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/mozart-issue-62",
  "require": {
    "aws/aws-sdk-php": "2.8.31"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Strauss\\"
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

        $this->getFileSystem()->write($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $phpString = $this->getFileSystem()->read($this->testsWorkingDir .'vendor-prefixed/aws/aws-sdk-php/src/Aws/S3/S3Client.php');

        self::assertStringContainsString('use Strauss\\Guzzle\\Common\\Collection;', $phpString);
    }
}
