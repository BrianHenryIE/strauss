<?php
/**
 * TODO: update issue number
 *
 * Add functionality to change the namespace in the project's own source files.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/128
 */

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class ReplaceCommandIntegrationTest extends IntegrationTestCase
{
    public function test_rename_namespace()
    {
        $myPluginPhpString = <<<'EOD'
<?php
/**
 * Plugin Name: My Plugin
 */

namespace YourPlugin;

YourPluginClass::init();
EOD;

        $myPluginClassPhpString = <<<'EOD'
<?php
namespace YourPlugin;

class YourPluginClass {
	public static function init() {}
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/my-plugin.php', $myPluginPhpString);
        @mkdir($this->testsWorkingDir . 'includes');
        file_put_contents($this->testsWorkingDir . '/includes/class-my-plugin.php', $myPluginClassPhpString);

        $_SERVER['argv'] = [
            $this->projectDir . '/bin/strauss',
            'replace',
            '--from','YourPlugin',
            '--to','BrianHenryIE\\MyPlugin'
        ];

        $version = '0.19.1';
        $app = new \BrianHenryIE\Strauss\Console\Application($version);
        $app->setAutoExit(false);
        $exitCode = $app->run();

        $php_string = file_get_contents($this->testsWorkingDir . '/my-plugin.php');

        self::assertStringContainsString('namespace BrianHenryIE\MyPlugin;', $php_string);
    }
}
