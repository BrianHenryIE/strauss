<?php
/**
 * Copy all files in Fremius / Action Scheduler / Plugin Update Checker packages.
 *
 * TODO: But these packages should probably not be prefixed. They each have their own namespacing mechanism.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/207
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue207Test extends IntegrationTestCase
{
    public function test_fremius_files_are_copied(): void
    {
        $packageComposerJson = <<<'EOD'
{
	"name": "test/package-with-custom-autoloader",
    "extra": {
        "strauss": {
            "namespace_prefix": "Strauss\\Issue207\\"
        }
    },
    "require": {
        "freemius/wordpress-sdk": "2.12"
    }
}
EOD;
        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $packageComposerJson);

        chdir($this->testsWorkingDir);
        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        // Expected anyway.
        $this->assertFileExists($this->testsWorkingDir . '/vendor-prefixed/freemius/wordpress-sdk/start.php');
        // Not part of the autoloader.
        $this->assertFileExists($this->testsWorkingDir . '/vendor-prefixed/freemius/wordpress-sdk/config.php');

        // Do not prefix.
        $php_string = $this->getFileSystem()->read($this->testsWorkingDir . 'vendor-prefixed/freemius/wordpress-sdk/includes/class-freemius.php');
        $this->assertStringContainsString("class Freemius extends Freemius_Abstract", $php_string);
    }

    public function test_action_scheduler_files_are_copied(): void
    {
        $packageComposerJson = <<<'EOD'
{
	"name": "test/package-with-custom-autoloader",
    "extra": {
        "strauss": {
            "namespace_prefix": "Strauss\\Issue207_2\\"
        }
    },
    "require": {
        "woocommerce/action-scheduler": "3.9.3"
    }
}
EOD;
        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $packageComposerJson);

        chdir($this->testsWorkingDir);
        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $this->assertFileExists($this->testsWorkingDir . '/vendor-prefixed/woocommerce/action-scheduler/action-scheduler.php');

        // Do not prefix.
        $php_string = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/woocommerce/action-scheduler/classes/actions/ActionScheduler_Action.php');
        $this->assertStringContainsString("class ActionScheduler_Action {", $php_string);
    }

    public function test_plugin_update_checker_files_are_copied(): void
    {
        $packageComposerJson = <<<'EOD'
{
	"name": "test/package-with-custom-autoloader",
    "extra": {
        "strauss": {
            "namespace_prefix": "Strauss\\Issue207_3\\"
        }
    },
    "require": {
        "yahnis-elsts/plugin-update-checker": "v5.6"
    }
}
EOD;
        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $packageComposerJson);

        chdir($this->testsWorkingDir);
        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $this->assertFileExists($this->testsWorkingDir . '/vendor-prefixed/yahnis-elsts/plugin-update-checker/plugin-update-checker.php');

        $this->markTestSkipped("I'm unsure what the best thing to do here is. Should the files be prefixed or not?");

        // Do not prefix.
        $php_string = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/yahnis-elsts/plugin-update-checker/Puc/v5p6/Autoloader.php');
        $this->assertStringContainsString("namespace YahnisElsts\\PluginUpdateChecker\\v5p6;", $php_string);
    }

    public function test_abilities_api_files_are_copied(): void
    {
        $packageComposerJson = <<<'EOD'
{
	"name": "test/abilities-api-uses-bootstrap-in-files-autoloader",
    "extra": {
        "strauss": {
            "namespace_prefix": "Strauss\\Issue207_4\\"
        }
    },
    "require": {
        "wordpress/abilities-api": "0.4.0"
    }
}
EOD;
        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $packageComposerJson);

        chdir($this->testsWorkingDir);
        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $this->assertFileExists($this->testsWorkingDir . '/vendor-prefixed/wordpress/abilities-api/includes/abilities-api.php');

        // Do not prefix.
        $php_string = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/wordpress/abilities-api/includes/abilities-api.php');
        $this->assertStringContainsString("function wp_register_ability(", $php_string);
    }
}
