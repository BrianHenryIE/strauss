<?php
/**
 * Carbon Fields, including non-class files
 * @see https://github.com/coenjacobs/mozart/issues/130
 *
 * Basically, Mozart does not support `files` autoloaders. Strauss does!
 *
 * @author BrianHenryIE
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * Class MozartIssue130Test
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class MozartIssue130Test extends IntegrationTestCase
{

    /**
     * @author BrianHenryIE
     */
    public function test_config_copied()
    {
        $this->markTestSkipped('too slow');

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/mozart-issue-130",
  "require": {
    "htmlburger/carbon-fields": "v3.3.3"
  },
  "extra": {
    "mozart":{
      "dep_namespace": "MZoo\\MzMboAccess\\",
      "dep_directory": "/strauss/",
      "override_autoload": {
        "htmlburger/carbon-fields": {
          "psr-4": {
            "Carbon_Fields\\": "core/"
          },
          "files": [
            "config.php",
            "templates",
            "assets",
            "build"
          ]
        }
      }
    }
  }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        self::assertFileExists($this->testsWorkingDir .'strauss/htmlburger/carbon-fields/config.php');
    }
}
