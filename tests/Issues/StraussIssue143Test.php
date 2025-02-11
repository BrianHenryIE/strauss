<?php
/**
 * Error when composer.json is in a subdirectory of the project; a sibling diretcory of the vendor directory.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/143
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue143Test extends IntegrationTestCase
{
    public function test_composer_in_sibling_dir()
    {

        $composerJsonString = <<<'EOD'
{
    "name": "strauss/issue143",
    "require": {
        "psr/log": "1.0.0"
    },
    "config": {
        "vendor-dir": "../vendor/"
    },
    "extra": {
      "strauss": {
        "namespace_prefix": "Strauss\\Issue143\\",
        "target_directory": "../vendor-prefixed"
      }
    }
}
EOD;

        mkdir($this->testsWorkingDir . '/build');
        mkdir($this->testsWorkingDir . '/src');
        chdir($this->testsWorkingDir . '/build');

        file_put_contents($this->testsWorkingDir . '/build/composer.json', $composerJsonString);

        exec('composer install');

        /**
         * @see DependenciesCommand::execute()
         */
        $exitCode = $this->runStrauss();

        $this->assertEquals(0, $exitCode);

        $this->assertFileExists($this->testsWorkingDir . '/vendor-prefixed/psr/log/Psr/Log/LoggerInterface.php');
        $phpString = file_get_contents($this->testsWorkingDir . '/vendor-prefixed/psr/log/Psr/Log/LoggerInterface.php');
        $this->assertStringContainsString('namespace Strauss\\Issue143\\Psr\\Log;', $phpString);

        $classmapString = file_get_contents($this->testsWorkingDir . '/vendor-prefixed/autoload-classmap.php');
        $this->assertStringNotContainsString($this->testsWorkingDir, $classmapString);
    }
}
