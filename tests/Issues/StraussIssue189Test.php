<?php
/**
 * namespaced trait name
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/166
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue189Test extends IntegrationTestCase
{
    public function test_unprefixed_in_composer_autoload_psr4_file()
    {

        $composerJsonString = <<<'EOD'
{
    "name": "dartui/strauss-illuminate-contracts",
    "type": "project",
    "autoload": {
        "psr-4": {
            "Dartui\\StraussIlluminateContracts\\": "src/"
        }
    },
    "require": {
        "illuminate/support": "12.10",
        "illuminate/collections": "12.10"
    },
    "extra": {
        "strauss": {
            "target_directory": "dependencies",
            "namespace_prefix": "StraussIlluminateContracts\\Deps\\",
            "classmap_prefix": "SIC"
        }
    }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $php_string = file_get_contents($this->testsWorkingDir . '/dependencies/composer/autoload_psr4.php');

        $this->assertStringNotContainsString("'Illuminate\\\\Contracts\\\\'", $php_string);

        $this->assertStringContainsString("'StraussIlluminateContracts\\\\Deps\\\\Illuminate\\\\Contracts\\\\'", $php_string);
    }
}
