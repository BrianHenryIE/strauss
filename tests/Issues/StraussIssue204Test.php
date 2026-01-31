<?php
/**
 * @see https://github.com/BrianHenryIE/strauss/issues/204
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue204Test extends IntegrationTestCase
{
    public function test_allow_specifying_alternative_composerjson()
    {

        $composerJsonString = <<<'EOD'
{
	"name": "saltus/interactive-globes",
	"config": {
		"vendor-dir": "../vendor/"
	},
	"require": {
		"psr/log": "*",
		"psr/container": "*"
	},
	"extra": {
		"strauss": {
			"namespace_prefix": "Saltus\\WP\\Plugin\\InteractiveGlobes\\",
			"target_directory": "../vendor-prefixed"
		}
	}
}
EOD;

        $composerFreeJsonString = <<<'EOD'
{
	"name": "saltus/interactive-globes-free",
	"config": {
		"vendor-dir": "../vendor/"
	},
	"require": {
		"psr/log": "*"
	},
	"extra": {
		"strauss": {
			"namespace_prefix": "Saltus\\WP\\Plugin\\InteractiveGlobes\\",
			"target_directory": "../vendor-prefixed"
		}
	}
}
EOD;

        mkdir($this->testsWorkingDir . '/projectdir');
        chdir($this->testsWorkingDir . '/projectdir');

        $this->getFileSystem()->write($this->testsWorkingDir . '/projectdir/composer.json', $composerJsonString);
        $this->getFileSystem()->write($this->testsWorkingDir . '/projectdir/composer-free.json', $composerFreeJsonString);

        exec('COMPOSER=composer-free.json composer install');

        $env = 'COMPOSER=composer-free.json';
        $exitCode = $this->runStrauss($output, '', $env);
        $this->assertEquals(0, $exitCode, $output);

        $php_string = $this->getFileSystem()->read($this->testsWorkingDir . 'vendor-prefixed/composer/installed.json');
        $this->assertStringContainsString("Saltus\\\\WP\\\\Plugin\\\\InteractiveGlobes\\\\Psr\\\\Log\\\\", $php_string);
    }
}
