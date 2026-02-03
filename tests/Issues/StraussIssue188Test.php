<?php
/**
 * Improper dealing with global namespaces
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/172
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue188Test extends IntegrationTestCase
{
    public function test_issue_188_implements()
    {
        $composerJsonString = <<<'EOD'
{
  "name": "issue/188",
  "require": {
    "guzzlehttp/guzzle": "7.9"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\PluginFramework\\"
    }   
  }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install --no-dev');
        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $php_string = $this->getFileSystem()->read($this->testsWorkingDir . 'vendor-prefixed/guzzlehttp/guzzle/src/Client.php');

        $this->assertStringNotContainsString("class Client implements ClientInterface, \\\\Psr\\Http\\Client\\ClientInterface", $php_string);
        $this->assertStringContainsString("class Client implements ClientInterface, \\Company\\PluginFramework\\Psr\\Http\\Client\\ClientInterface", $php_string);
    }


    public function test_issue_188_extends()
    {
        $composerJsonString = <<<'EOD'
{
  "name": "issue/188",
  "require": {
    "mpdf/mpdf": "v8.2.6"
  },
  "extra": {
    "strauss": {
      "override_autoload": {
        "mpdf/mpdf": {
          "files": [
            "data/",
            "src/",
            "tmp/",
            "ttfonts"
          ]
        }
      },
      "namespace_prefix": "Company\\PluginFramework\\"
    }   
  }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install --no-dev');
        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $php_string = $this->getFileSystem()->read($this->testsWorkingDir . 'vendor-prefixed/mpdf/mpdf/src/Exception/FontException.php');

        $this->assertStringNotContainsString("class FontException extends \\Mpdf\\MpdfException", $php_string);
        $this->assertStringNotContainsString("class FontException extends \\\\Company\\PluginFramework\\Mpdf\\MpdfException", $php_string);
        $this->assertStringContainsString("class FontException extends \\Company\\PluginFramework\\Mpdf\\MpdfException", $php_string);
    }
}
