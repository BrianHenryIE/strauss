<?php
/**
 * Also prefix global functions
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/74
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue74Test extends IntegrationTestCase
{
    /**
     */
    public function test_prefix_global_function()
    {

        $composerJsonString = <<<'EOD'
{
  "require": {
	"illuminate/support": "v8.83.27"
  },
  "extra": {
    "strauss": {
      "target_directory": "vendor-prefixed",
      "namespace_prefix": "My\\Prefix\\",
      "classmap_prefix": "MyPrefix_"
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $result = $this->runStrauss();

        $phpString = file_get_contents($this->testsWorkingDir .'vendor-prefixed/illuminate/support/helpers.php');

        $this->assertStringNotContainsString('function append_config(array $array)', $phpString);
        $this->assertStringContainsString('function myprefix_append_config(array $array)', $phpString);

        $this->assertStringNotContainsString('if (! function_exists(\'append_config\')) {', $phpString);
        $this->assertStringContainsString('if (! function_exists(\'myprefix_append_config\')) {', $phpString);
    }
}
