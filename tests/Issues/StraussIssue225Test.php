<?php
/**
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/225
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue225Test extends IntegrationTestCase
{

    public function test_non_autoloaded_template_file_has_namespace_updated(): void
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

        $dependencyNotAutoloadedString = <<<'EOD'
<?php

namespace My\Dependency;

echo "template";
EOD;

        $mainComposerJsonString = <<<'EOD'
{
  "name": "strauss/issue225",
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
      "namespace_prefix": "BrianHenryIE\\Strauss\\"
    }
  }
}
EOD;

        mkdir($this->testsWorkingDir . 'dependency');
        file_put_contents($this->testsWorkingDir . 'dependency/composer.json', $dependencyComposerJsonString);
        mkdir($this->testsWorkingDir . 'dependency/src');
        $psr4AutoloadedFilePath = $this->testsWorkingDir . 'dependency/src/Psr4Autoloaded.php';
        file_put_contents($psr4AutoloadedFilePath, $dependencyPsr4AutoloadedString);
        mkdir($this->testsWorkingDir . 'dependency/templates');
        $notAutoloadedFilePath = $this->testsWorkingDir . 'dependency/templates/notautoloaded.php';
        file_put_contents($notAutoloadedFilePath, $dependencyNotAutoloadedString);

        mkdir($this->testsWorkingDir . 'project');
        file_put_contents($this->testsWorkingDir . 'project/composer.json', $mainComposerJsonString);
        chdir($this->testsWorkingDir . 'project');
        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $php_string = file_get_contents($this->testsWorkingDir . '/project/vendor-prefixed/strausstest/dependency/src/Psr4Autoloaded.php');
        $this->assertStringContainsString('namespace BrianHenryIE\\Strauss\\My\\Dependency;', $php_string);

        $php_string = file_get_contents($this->testsWorkingDir . '/project/vendor-prefixed/strausstest/dependency/templates/notautoloaded.php');
        $this->assertStringNotContainsString('namespace My\\Dependency;', $php_string);
        $this->assertStringContainsString('namespace BrianHenryIE\\Strauss\\My\\Dependency;', $php_string);
    }
}
