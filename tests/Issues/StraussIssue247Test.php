<?php
/**
 * [warning] Package directory unexpectedly DOES NOT exist: /path/to/vendor-prefixed/freemius/wordpress-sdk
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/249
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue247Test extends IntegrationTestCase
{

    public function test_return_type_double_prefixed(): void
    {
        $this->markTestSkippedOnPhpVersionBelow('8.1.0');

        $composerJsonString = <<<'EOD'
{
    "name": "issue247/webfx-wordpress-plugin-pokemon",
    "require": {
        "codekaizen/wp-package-auto-updater": "2.0.2"
    },
    "autoload": {
        "psr-4": {
            "WebFX\\WebFXWordPressPluginPokemon\\": "includes/"
        }
    },
    "extra": {
        "strauss": {
            "target_directory": "vendor",
            "constant_prefix": "WEBFX_WORDPRESS_PLUGIN_POKEMON_DEPENDENCIES_",
            "override_autoload": {
                "respect/stringifier": {
                    "psr-4": {
                        "Respect\\Stringifier\\": "src/"
                    },
                    "files": [
                        "stringify.php"
                    ]
                }
            }
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install', $composerInstallOutput, $composerInstallExitCode);
        $this->assertEquals(0, $composerInstallExitCode, implode(PHP_EOL, $composerInstallOutput));

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $phpString = $this->getFileSystem()->read($this->testsWorkingDir . 'vendor/codekaizen/wp-package-auto-updater/src/Value/PackageRoot/PluginPackageRootValue.php');
        $this->assertStringNotContainsString("WEBFX_WORDPRESS_PLUGIN_POKEMON_DEPENDENCIES_WP_PLUGIN_DIR", $phpString);
        $this->assertStringContainsString("return WP_PLUGIN_DIR;", $phpString);
    }
}
