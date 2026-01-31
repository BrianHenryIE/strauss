<?php
/**
 * @see https://github.com/coenjacobs/mozart/blob/3b1243ca8505fa6436569800dc34269178930f39/tests/replacers/ClassmapReplacerIntegrationTest.php
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * Class MozartIssue106Test
 * @coversNothing
 */
class MozartIssue106Test extends IntegrationTestCase
{

    /**
     * Issue #106, multiple classmap prefixing.
     *
     * @see https://github.com/coenjacobs/mozart/issues/106
     */
    public function test_only_prefix_classmap_classes_once()
    {
        /**
         * @see https://github.com/BrianHenryIE/strauss/commit/1bd20b75a4e6b5c07a428c04e8b9e514034b6b5c
         */
        self::markTestSkipped('Polyfills are no longer prefixed.');

        $composerJsonString = <<<'EOD'
{
	"name": "brianhenryie/mozart-issue-106",
	"require": {
		"symfony/polyfill-intl-idn":  "1.22.0",
        "symfony/polyfill-intl-normalizer": "1.22.0"
	},
	"extra": {
		"strauss": {
			"namespace_prefix": "BrianHenryIE\\Strauss\\",
			"classmap_prefix": "BrianHenryIE_Strauss_"
		}
	}
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $php_string = $this->getFileSystem()->read($this->testsWorkingDir .'vendor-prefixed/symfony/polyfill-intl-normalizer/Resources/stubs/Normalizer.php');

        // Confirm problem is gone.
        self::assertStringNotContainsString('class BrianHenryIE_Strauss_BrianHenryIE_Strauss_Normalizer extends', $php_string, 'Double prefixing problem still present.');

        // Confirm solution is correct.
        self::assertStringContainsString('class BrianHenryIE_Strauss_Normalizer extends', $php_string, 'Class name not properly prefixed.');
    }
}
