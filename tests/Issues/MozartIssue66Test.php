<?php
/**
 * Packages with files autoloaders do not autoload those files
 * @see https://github.com/coenjacobs/mozart/issues/66
 *
 *
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * Class MozartIssue66Test
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class MozartIssue66Test extends IntegrationTestCase
{

    /**
     *
     * php-di's composer.json's autoload key:
     *
     * "autoload": {
     *    "psr-4": {
     *      "DI\\": "src/"
     *     },
     *     "files": [
     *        "src/functions.php"
     *    ]
     * },
     */
    public function testFilesAutoloaderIsUsed()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "markjaquith/mozart-bug-example",
  "require": {
    "php-di/php-di": "^6.0"
  },
  "extra": {
    "mozart": {
        "dep_namespace": "MarkJaquith\\",
        "dep_directory": "/strauss/",
        "delete_vendor_files": false
    }
  },
  "autoload": {
    "classmap": [
      "lib/Mozart/classmaps/"
    ],
    "psr-4": {
        "MarkJaquith\\MozartFileAutoloaderBug\\Mozart\\": "lib/Mozart/",
        "MarkJaquith\\MozartFileAutoloaderBug\\": "app/"
    }
  }
}

EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output, '--debug');
        assert(0 === $exitCode, $output);

        self::assertFileExists($this->testsWorkingDir . 'strauss/php-di/php-di/src/functions.php');
    }
}
