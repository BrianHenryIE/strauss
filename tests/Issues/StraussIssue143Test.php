<?php
/**
 * Error when composer.json is in a subdirectory of the project; a sibling diretcory of the vendor directory.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/143
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue143Test extends IntegrationTestCase
{
    public function test_composer_in_sibling_dir()
    {

        $composerJsonString = <<<'EOD'
{
    "name": "strauss/issue143",
    "require": {
        "psr/log": "1.0.0"
    },
    "config": {
        "vendor-dir": "../vendor/"
    },
    "extra": {
      "strauss": {
        "namespace_prefix": "Strauss\\Issue143\\",
        "target_directory": "../vendor-prefixed"
      }
    }
}
EOD;

        mkdir($this->testsWorkingDir . '/build');
        mkdir($this->testsWorkingDir . '/src');
        chdir($this->testsWorkingDir . '/build');

        file_put_contents($this->testsWorkingDir . '/build/composer.json', $composerJsonString);

        exec('composer install');

        /**
         * @see DependenciesCommand::execute()
         */
        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $this->assertFileExists($this->testsWorkingDir . 'vendor-prefixed/psr/log/Psr/Log/LoggerInterface.php');
        $phpString = file_get_contents($this->testsWorkingDir . '/vendor-prefixed/psr/log/Psr/Log/LoggerInterface.php');
        $this->assertStringContainsString('namespace Strauss\\Issue143\\Psr\\Log;', $phpString);

        $this->assertFileExists($this->testsWorkingDir . 'vendor-prefixed/autoload.php');

        $installedJsonString = file_get_contents($this->testsWorkingDir . '/vendor-prefixed/composer/installed.json');
        $this->assertStringContainsString('"name": "psr/log",', $installedJsonString);

        $exitCode = $this->runStrauss($output, 'include-autoloader');
        assert(0 === $exitCode, $output);

        $classmapString = file_get_contents($this->testsWorkingDir . '/vendor-prefixed/composer/autoload_classmap.php');
        $this->assertStringContainsString('/psr/log/Psr/Log/LoggerAwareInterface.php', $classmapString);
        $this->assertStringNotContainsString('\'Psr\\\\Log\\\\NullLogger', $classmapString);
        $this->assertStringContainsString('\'Strauss\\\\Issue143\\\\Psr\\\\Log\\\\NullLogger', $classmapString);

        exec('php -r "include __DIR__ . \'/../vendor/autoload.php\'; new \Psr\Log\NullLogger();" 2>&1', $output, $result_code);
        $outputString = implode(PHP_EOL, $output);

        $this->assertEquals(0, $result_code, $outputString);

        exec('php -r "include __DIR__ . \'/../vendor/autoload.php\'; new \Strauss\Issue143\Psr\Log\NullLogger();" 2>&1', $output, $result_code);
        $outputString = implode(PHP_EOL, $output);

        $this->assertEquals(0, $result_code, $outputString);
    }

    /**
     * @see https://github.com/BrianHenryIE/strauss/issues/143#issuecomment-2684239222
     */
    public function test_composer_in_sibling_dir_delete_packages()
    {

        $composerJsonString = <<<'EOD'
{
    "name": "strauss/issue143",
    "require": {
        "psr/log": "1.0.0"
    },
    "config": {
        "vendor-dir": "../vendor/"
    },
    "extra": {
      "strauss": {
        "namespace_prefix": "Strauss\\Issue143\\",
        "target_directory": "../vendor-prefixed",
        "delete_vendor_packages": true
      }
    }
}
EOD;

        mkdir($this->testsWorkingDir . '/build');
        mkdir($this->testsWorkingDir . '/src');
        chdir($this->testsWorkingDir . '/build');

        file_put_contents($this->testsWorkingDir . '/build/composer.json', $composerJsonString);

        exec('composer install');

        /**
         * @see DependenciesCommand::execute()
         */
        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $this->assertFileDoesNotExist($this->testsWorkingDir . 'vendor/psr/log/Psr/Log/LoggerInterface.php');
    }

    /**
     * symfony/console 7.2 adds a silent option to all commands. Since Strauss is also adding `silent`, we need to
     * only do that for older versions of Symfony Console, and test behavior works correctly for 7.2+.
     */
    public function test_silent_option_symfony_72(): void
    {
        $this->markTestSkippedOnPhpVersionAbove('8.2');
        $this->markTestSkippedOnPhpVersionBelow('8.3');

        $composerJsonString = <<<'EOD'
{
    "name": "strauss/issue143",
    "require": {
        "symfony/console": "7.2.5"
    },
    "extra": {
      "strauss": {
        "namespace_prefix": "Strauss\\Issue143\\"
      }
    }
}
EOD;

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);
        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        exec($this->testsWorkingDir . '/vendor/bin/strauss dependencies  2>&1', $output);

        $outputMerged = implode(PHP_EOL, $output);

        $this->assertStringNotContainsString(
            'An option named "silent" already exists',
            $outputMerged
        );
    }
}
