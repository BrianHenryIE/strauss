<?php
/**
 * Symlink removed although it is in exclude_from_copy
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/262
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @coversNothing
 */
class StraussIssue262Test extends IntegrationTestCase
{

    public function test_do_not_remove_symlink_exclude_from_copy(): void
    {

        $dependencyComposerJsonString = <<<'EOD'
{
	"name": "strausstest/dependency",
	"autoload": {
		"psr-4": {
			"My\\Dependency\\": "src"
		}
	}
}
EOD;

        $dependencyPsr4AutoloadedString = <<<'EOD'
<?php

namespace My\Dependency;

class Psr4Autoloaded {

}
EOD;

        $mainComposerJsonString = <<<'EOD'
{
  "name": "strauss/issue262",
  "minimum-stability": "dev",
  "repositories": {
    "strausstest/dependency": {
        "type": "path",
        "url": "../dependency"
    }
  },
  "require": {
    "strausstest/dependency": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\S262\\",
      "delete_vendor_packages": true,
      "exclude_from_copy": {
        "packages": [
          "strausstest/dependency"
        ]
      }
    }
  }
}
EOD;

        mkdir($this->testsWorkingDir . '/dependency');
        $this->getFileSystem()->write($this->testsWorkingDir . '/dependency/composer.json', $dependencyComposerJsonString);
        mkdir($this->testsWorkingDir . '/dependency/src');
        $psr4AutoloadedFilePath = $this->testsWorkingDir . '/dependency/src/Psr4Autoloaded.php';
        $this->getFileSystem()->write($psr4AutoloadedFilePath, $dependencyPsr4AutoloadedString);

        mkdir($this->testsWorkingDir . '/project');
        $this->getFileSystem()->write($this->testsWorkingDir . '/project/composer.json', $mainComposerJsonString);
        chdir($this->testsWorkingDir . '/project');
        exec('composer install');

        // teststempdir/x/project/vendor/strausstest/dependency
        $this->assertFileExists($this->testsWorkingDir . '/project/vendor/strausstest/dependency');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $this->assertFileExists($this->testsWorkingDir . '/project/vendor/strausstest/dependency');
    }
}
