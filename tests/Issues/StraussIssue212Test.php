<?php
/**
 * `symfony/polyfill-php83` fatal error.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/212
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue212Test extends IntegrationTestCase
{
    public function test_symfony_polyfill_php83(): void
    {
        $packageComposerJson = <<<'EOD'
{
    "name": "sample/strauss-212",
    "description": "Minimum example of issue 212.",
    "type": "wordpress-plugin",
    "license": "MIT",
    "autoload": {
        "psr-4": {
            "Sample\\Strauss212\\": "src/"
        }
    },
    "minimum-stability": "stable",
    "require": {
        "symfony/polyfill-php83": "1.32"
    }
}
EOD;
        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $packageComposerJson);

        chdir($this->testsWorkingDir);
        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        // Seems ok.
    }
}
