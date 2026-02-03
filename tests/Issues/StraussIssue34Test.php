<?php
/**
 * Don't double prefix when updating project code on repeated runs.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/34
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue34Test extends IntegrationTestCase
{

    public function test_no_double_prefix_after_second_run()
    {
        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss-34",
  "minimum-stability": "dev",
  "autoload": {
    "classmap": [
      "src/"
    ]
  },
  "require": {
    "psr/log": "1"
  },
  "require-dev": {
    "phpunit/phpunit": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BH_Strauss_",
      "target_directory": "vendor",
      "update_call_sites": true
    }
  }
}
EOD;
        $phpFileJsonString = <<<'EOD'
<?php 

namespace My_Namespace\My_Project;

use Psr\Log\LoggerInterface;
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . 'composer.json', $composerJsonString);
        @mkdir($this->testsWorkingDir . 'src');
        $this->getFileSystem()->write($this->testsWorkingDir . 'src/library.php', $phpFileJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);
        // Run TWICE!
        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $project_file_php_string = $this->getFileSystem()->read($this->testsWorkingDir . 'src/library.php');
        self::assertStringNotContainsString('use Psr\Log\LoggerInterface', $project_file_php_string);
        self::assertStringContainsString('use BrianHenryIE\Strauss\Psr\Log\LoggerInterface', $project_file_php_string);

        $project_file_php_string = $this->getFileSystem()->read($this->testsWorkingDir . 'vendor/psr/log/Psr/Log/LoggerInterface.php');
        self::assertStringNotContainsString('namespace Psr\Log;', $project_file_php_string);
        self::assertStringContainsString('namespace BrianHenryIE\Strauss\Psr\Log;', $project_file_php_string);
    }
}
