<?php
/**
 * Partial matches. I.e. `$classname = "My_Project_" . $suffix"; class_exists( $classname );
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/153
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @coversNothing
 */
class StraussIssue153Test extends IntegrationTestCase
{
    // TODO: This name is wrong and this test/issue number is wrong.
    // We are testing a case where there is no autoload key in the package so Strauss is not renaming.
    // Currently, 0.29.0, all PHP files are copied, only symbols in PHP files considered autoloaded are renamed.
    public function test_concatenated_string_namespace(): void
    {

        // 4.0.4 but that might not load on PHP 7.4.
        $composerJsonString = <<<'EOD'
{
    "require": {
        "squizlabs/php_codesniffer": "*"
    },
    "extra": {
        "strauss": {
            "classmap_prefix": "Strauss_Issue153_",
            "namespace_prefix": "Strauss\\Issue153\\",
            "override_autoload": {
                "squizlabs/php_codesniffer": {
                    "classmap": [ "." ]
                }
            }
        }
    }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        /**
         * @see DependenciesCommand::execute()
         */
        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $phpStringAfter = $this->getFileSystem()->read($this->testsWorkingDir .'/vendor-prefixed/squizlabs/php_codesniffer/autoload.php');

        // squizlabs/php_codesniffer/autoload.php:23
        // if (class_exists('PHP_CodeSniffer\Autoload', false) === false) {
        $this->assertStringNotContainsString("class_exists('PHP_CodeSniffer\Autoload', false)", $phpStringAfter);
        $this->assertStringContainsString("class_exists('Strauss\Issue153\PHP_CodeSniffer\Autoload', false)", $phpStringAfter);

        // squizlabs/php_codesniffer/autoload.php:101
        // if (substr($className, 0, 16) === 'PHP_CodeSniffer\\') {
        $this->assertStringNotContainsString('=== \'PHP_CodeSniffer\\\\\')', $phpStringAfter);
        $this->assertStringContainsString('=== \'Strauss\\Issue153\\PHP_CodeSniffer\\\\\')', $phpStringAfter);

        // squizlabs/php_codesniffer/autoload.php:102
        //  if (substr($className, 0, 22) === 'PHP_CodeSniffer\Tests\\') {
        $this->assertStringNotContainsString('=== \'PHP_CodeSniffer\\Tests\\\\\')', $phpStringAfter);
        $this->assertStringContainsString('=== \'Strauss\\Issue153\\PHP_CodeSniffer\\Tests\\\\\')', $phpStringAfter);

        // squizlabs/php_codesniffer/autoload.php:77
        // if (strpos($className, 'Composer\\') === 0) {
        // if (strpos($className, 'Strauss\Issue153\Composer\\') === 0) {
        $this->assertStringNotContainsString('\'Composer\\\\\'', $phpStringAfter);
        $this->assertStringContainsString('\'Strauss\\Issue153\\Composer\\\\\'', $phpStringAfter);
    }
}
