<?php
/**
 * Error when `config.vendor-dir` is multiple directories deep.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/136
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue136Test extends IntegrationTestCase
{
    /**
     * `"update_call_sites": true` would update the source files.
     */
    public function test_does_not_update_source_files_unless_requested()
    {
        $composerJsonString = <<<'EOD'
{
  "name": "strauss/issue136",
  "autoload": {
    "psr-4": {
      "BrianHenryIE\\Strauss\\": "src"
    }
  },
  "require": {
    "symfony/var-dumper": "^6.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\",
      "classmap_prefix": "Company_Project_"
	}
  }
}
EOD;

        $phpString =<<<'EOD'
<?php

namespace BrianHenryIE\Strauss;

class Whatever {

	public function execute(): void {
		$var = new \Symfony\Component\VarDumper\VarDumper();
	} 
}
EOD;

        $expectedPhpString =<<<'EOD'
<?php

namespace BrianHenryIE\Strauss;

class Whatever {

	public function execute(): void {
		$var = new \Symfony\Component\VarDumper\VarDumper();
	} 
}
EOD;


        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);
        mkdir($this->testsWorkingDir . 'src');
        file_put_contents($this->testsWorkingDir . 'src/whatever.php', $phpString);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $phpStringAfter = file_get_contents($this->testsWorkingDir . '/src/whatever.php');

        $this->assertEquals($expectedPhpString, $phpStringAfter);
    }
}
