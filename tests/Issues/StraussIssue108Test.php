<?php
/**
 * `use GlobalClass as Alias;` should be replaced with `use Prefixed_GlobalClass as Alias;`.
 *
 *
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/108
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue108Test extends IntegrationTestCase
{
    public function test_a()
    {
        $composerJsonString = <<<'EOD'
{
  "name": "strauss/issue108",
  "require": {
    "erusev/parsedown": "1.7.4"
  },
  "autoload": {
    "classmap": [
	  "src/"
	]
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Strauss\\Issue108\\",
      "classmap_prefix": "Prefixed_",
	  "override_autoload": {
		"erusev/parsedown": {
	  	  "classmap": [
		    "."
		  ]
		}
	  },
	  "update_call_sites": true
    }
  }
}
EOD;

        $replacementfile = <<<'EOD'
<?php

use Parsedown as MarkdownParser;

class MyClass {
	public function myFunction() {
		$parsedown = new MarkdownParser();
	}
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        @mkdir($this->testsWorkingDir . 'src');
        $replacementfilePath = $this->testsWorkingDir . '/src/file.php';
        $this->getFileSystem()->write($replacementfilePath, $replacementfile);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $php_string = $this->getFileSystem()->read($replacementfilePath);

        self::assertStringNotContainsString("use Parsedown as MarkdownParser;", $php_string);
        self::assertStringContainsString("use Prefixed_Parsedown as MarkdownParser;", $php_string);
    }
}
