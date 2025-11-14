<?php
/**
 * When users migrate from Mozart, the settings are only preserved when the extra "mozart" key
 * is still used. Let's change it so they're understood not matter what.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/11
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\IntegrationTestCase;
use Composer\Factory;
use Composer\IO\NullIO;
use Symfony\Component\Console\Input\InputInterface;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue11Test extends IntegrationTestCase
{

    /**
     * @author BrianHenryIE
     */
    public function test_migrate_mozart_config()
    {
        $this->markTestSkipped('too slow');

        $composerExtraStraussJson = <<<'EOD'
{
	"name": "brianhenryie/strauss-issue-8",
	"extra": {
		"mozart": {
			"dep_namespace": "MZoo\\MBO_Sandbox\\Dependencies\\",
			"dep_directory": "/src/Mozart/",
			"packages": [
				"ericmann/wp-session-manager",
				"ericmann/sessionz"
			],
			"delete_vendor_files": false,
			"override_autoload": {
				"htmlburger/carbon-fields": {
					"psr-4": {
						"Carbon_Fields\\": "core/"
					},
					"files": [
						"config.php",
						"templates",
						"assets",
						"build"
					]
				}
			}
		}
	}
}
EOD;

        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $input = $this->createMock(InputInterface::class);
        $straussConfig = new StraussConfig($composer, $input);

        self::assertEqualsRN('src/Mozart/', $straussConfig->getTargetDirectory());

        self::assertEqualsRN("MZoo\\MBO_Sandbox\\Dependencies", $straussConfig->getNamespacePrefix());
    }



    /**
     * @author BrianHenryIE
     */
    public function test_carbon_fields()
    {
        $this->markTestSkipped('too slow');

        $composerJsonString = <<<'EOD'
{
	"name": "brianhenryie/strauss-issue-8",
	"require":{
	    "htmlburger/carbon-fields": "*"
	},
	"extra": {
		"mozart": {
			"dep_namespace": "MZoo\\MBO_Sandbox\\Dependencies\\",
			"dep_directory": "/src/Mozart/",
			"packages": [
				"htmlburger/carbon-fields"
			],
			"delete_vendor_files": false,
			"override_autoload": {
				"htmlburger/carbon-fields": {
					"psr-4": {
						"Carbon_Fields\\": "core/"
					},
					"files": [
						"config.php",
						"templates",
						"assets",
						"build"
					]
				}
			}

		}
	}
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $phpString = file_get_contents($this->testsWorkingDir .'src/Mozart/htmlburger/carbon-fields/core/Carbon_Fields.php');

        // This was not being prefixed.
        self::assertStringNotContainsString('$ioc->register( new \Carbon_Fields\Provider\Container_Condition_Provider() );', $phpString);

        self::assertStringContainsString('$ioc->register( new \MZoo\MBO_Sandbox\Dependencies\Carbon_Fields\Provider\Container_Condition_Provider() );', $phpString);
    }


    /**
     * @author BrianHenryIE
     */
    public function test_static_namespace()
    {
        $this->markTestSkipped('too slow');

        $composerJsonString = <<<'EOD'
{
	"name": "brianhenryie/strauss-issue-8",
	"require":{
	    "htmlburger/carbon-fields": "*"
	},
	"extra": {
		"mozart": {
			"dep_namespace": "MZoo\\MBO_Sandbox\\Dependencies\\",
			"dep_directory": "/src/Mozart/",
			"packages": [
				"htmlburger/carbon-fields"
			],
			"delete_vendor_files": false,
			"override_autoload": {
				"htmlburger/carbon-fields": {
					"psr-4": {
						"Carbon_Fields\\": "core/"
					},
					"files": [
						"config.php",
						"templates",
						"assets",
						"build"
					]
				}
			}

		}
	}
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $phpString = file_get_contents($this->testsWorkingDir .'src/Mozart/htmlburger/carbon-fields/core/Container.php');

        // This was not being prefixed.
        self::assertStringNotContainsString('@method static \Carbon_Fields\Container\Comment_Meta_Container', $phpString);

        self::assertStringContainsString('@method static \MZoo\MBO_Sandbox\Dependencies\Carbon_Fields\Container\Comment_Meta_Container', $phpString);
    }
}
