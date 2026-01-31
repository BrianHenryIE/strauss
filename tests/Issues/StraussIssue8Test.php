<?php
/**
 * @see https://github.com/BrianHenryIE/strauss/issues/8
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue8Test extends IntegrationTestCase
{

    /**
     * @author BrianHenryIE
     */
    public function test_delete_vendor_files()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss-issue-8",
  "require": {
    "psr/log": "1"
  },
  "extra": {
    "strauss":{
      "delete_vendor_files": true
    }
  }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        assert(file_exists($this->testsWorkingDir. 'vendor/psr/log/Psr/Log/LogLevel.php'));

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        self::assertFileDoesNotExist($this->testsWorkingDir. 'vendor/psr/log/Psr/Log/LogLevel.php');
    }
}
