<?php
/**
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue163Test extends IntegrationTestCase
{
    /**
     * Fatal error: Uncaught Error: Call to undefined function data_get() in test.php:8
     */
    public function test_multiple_autoloaders_breaks_autoloading()
    {
        $composerJsonString1 = <<<'EOD'
{
  "name": "strauss/issue163",
  "require": {
    "php": ">=7.4",
    "wp-forge/helpers": "2.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project1\\"
    }
  }
}
EOD;

        $composerJsonString2 = <<<'EOD'
{
  "name": "strauss/issue163",
  "require": {
    "php": ">=7.4",
    "wp-forge/helpers": "2.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project2\\"
    }
  }
}
EOD;

        mkdir($this->testsWorkingDir . 'project1');
        $this->getFileSystem()->write($this->testsWorkingDir . 'project1/composer.json', $composerJsonString1);
        chdir($this->testsWorkingDir . 'project1');
        exec('composer install --no-dev');
        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        mkdir($this->testsWorkingDir . 'project2');
        $this->getFileSystem()->write($this->testsWorkingDir . 'project2/composer.json', $composerJsonString2);
        chdir($this->testsWorkingDir . 'project2');
        exec('composer install --no-dev');
        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $project1files = include $this->testsWorkingDir . 'project1/vendor-prefixed/composer/autoload_files.php';
        $project2files = include $this->testsWorkingDir . 'project2/vendor-prefixed/composer/autoload_files.php';

        $project1index = null;
        foreach ($project1files as $index => $project1file) {
            if (false !== strpos($project1file, '/wp-forge/helpers/includes/functions.php')) {
                $project1index = $index;
                break;
            }
        }
        $project2index = null;
        foreach ($project2files as $index => $project2file) {
            if (false !== strpos($project2file, '/wp-forge/helpers/includes/functions.php')) {
                $project2index = $index;
                break;
            }
        }

        $this->assertNotEquals($project1index, $project2index);
    }
}
