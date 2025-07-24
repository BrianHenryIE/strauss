<?php
/**
 * Was over-eagerly deleting autoload keys.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/101#issuecomment-2078702245
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue101Test extends IntegrationTestCase
{
    public function test_does_not_delete_autoload_keys()
    {
        $composerJsonString = <<<'EOD'
{
    "require-dev": {
        "phpmd/phpmd": "2.15.0"
    },
    "scripts": {
        "phpmd": "./vendor/bin/phpmd src/ text phpmd-ruleset.xml"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "Ademti\\Test\\Dependencies",
            "classmap_prefix": "Ademti_Test_Dependencies",
            "constant_prefix": "A_T_D_",
            "delete_vendor_packages": true,
            "delete_vendor_files": true
        }
    }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        exec('composer dump-autoload', $output, $result_code);
        self::assertEquals(0, $result_code);

        $installed_json_string = file_get_contents($this->testsWorkingDir . '/vendor/composer/installed.json');
        $installed_json = json_decode($installed_json_string, true);

        $autoload = array();
        foreach ($installed_json['packages'] as $package) {
            if ($package['name'] === 'phpmd/phpmd') {
                $autoload = $package['autoload'];
                break;
            }
        }

        self::assertArrayHasKey('psr-0', $autoload);
    }
}
