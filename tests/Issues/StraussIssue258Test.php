<?php
/**
 * classmap prefix applied repeatedly
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/258
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @coversNothing
 */
class StraussIssue258Test extends IntegrationTestCase
{

    public function test_class_name_double_prefixed(): void
    {

        $composerJsonString = <<<'EOD'
{   
    "require": {
      "wp-media/wp-mixpanel": "1.4.0"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "Strauss\\Issue258\\",
            "target_directory": "vendor"
        }
    }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        // Run twice.
        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $phpString = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor/wp-media/wp-mixpanel/src/Classes/Mixpanel.php');
        $this->assertStringNotContainsString('class Strauss_Issue258_Strauss_Issue258_WPMedia_Mixpanel', $phpString);
        $this->assertStringContainsString('class Strauss_Issue258_WPMedia_Mixpanel', $phpString);
    }
}
