<?php
/**
 * @see https://github.com/BrianHenryIE/strauss/issues/14
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue14Test extends IntegrationTestCase
{

    /**
     * Looks like the exclude_from_prefix regex for psr is not specific enough.
     *
     * @author BrianHenryIE
     */
    public function test_guzzle_http_is_prefixed()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss-issue-14",
  "require":{
    "guzzlehttp/psr7": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\"
    }
  }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $php_string = $this->getFileSystem()->read($this->testsWorkingDir .'vendor-prefixed/guzzlehttp/psr7/src/AppendStream.php');

        // was namespace GuzzleHttp\Psr7;

        // Confirm solution is correct.
        self::assertStringContainsString('namespace BrianHenryIE\Strauss\GuzzleHttp\Psr7;', $php_string);
    }

    public function testFilesAutoloaderIsGenerated()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss-issue-14",
  "require":{
    "guzzlehttp/psr7": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\"
    }
  }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $this->assertFileExists($this->filesystem->normalize($this->testsWorkingDir .'vendor-prefixed/composer/autoload_files.php'));
    }
}
