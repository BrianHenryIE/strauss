<?php
/**
 * Don't fail when an autoloaded directory is missing.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/261
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @coversNothing
 * @see AutoloadedFilesEnumerator
 */
class StraussIssue261Test extends IntegrationTestCase
{

    public function test_skip_missing_dir(): void
    {

        $composerJsonString = <<<'EOD'
{
    "name": "strauss/issue261",
    "require": {
        "respect/stringifier": "1.0.0"
    },
    "extra": {
        "strauss": {
            "target_directory": "vendor",
            "namespace_prefix": "Project\\Prefix\\"
        }
    }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $this->assertStringContainsString('Skipping non-existent autoload path in', $output);
    }
}
