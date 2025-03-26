<?php
/**
 * @see https://github.com/BrianHenryIE/strauss/issues/154
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @coversNothing
 */
class StraussIssue154Test extends IntegrationTestCase
{
    public function test_relative_namespaces()
    {
        if (!version_compare(phpversion(), '8.4', '<')) {
            $this->markTestSkipped("Package specified for test is not PHP 8.4 compatible. Running tests under PHP " . phpversion());
        }

        $composerJsonString = <<<'EOD'
{
    "require": {
        "latte/latte": "2.11.7"
    },
    "extra": {
        "strauss": {
            "classmap_prefix": "StraussLatte_",
            "namespace_prefix": "StraussLatte\\"
        }
    }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        exec('composer install');

        /**
         * @see DependenciesCommand::execute()
         */
        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $phpString = file_get_contents($this->testsWorkingDir .'vendor-prefixed/latte/latte/src/Latte/Loaders/FileLoader.php');

        $this->assertStringNotContainsString('class FileLoader implements Latte\Loader', $phpString);
        $this->assertStringNotContainsString('class FileLoader implements StraussLatte\Latte\Loader', $phpString);
        $this->assertStringContainsString('class FileLoader implements \StraussLatte\Latte\Loader', $phpString);
    }

    /**
     * @see https://github.com/BrianHenryIE/strauss/pull/157#issuecomment-2753898094
     */
    public function test_use()
    {
        if (!version_compare(phpversion(), '8.4', '<')) {
            $this->markTestSkipped("Package specified for test is not PHP 8.4 compatible. Running tests under PHP " . phpversion());
        }

        $composerJsonString = <<<'EOD'
{
    "require": {
        "latte/latte": "2.11.7"
    },
    "extra": {
        "strauss": {
            "classmap_prefix": "StraussLatte_",
            "namespace_prefix": "StraussLatte\\"
        }
    }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        exec('composer install');

        /**
         * @see DependenciesCommand::execute()
         */
        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $phpString = file_get_contents($this->testsWorkingDir .'vendor-prefixed/latte/latte/src/Latte/Loaders/FileLoader.php');

        $this->assertStringNotContainsString('use Latte\Strict;', $phpString);
        $this->assertStringNotContainsString('use StraussLatte\Latte\Strict;', $phpString);
        $this->assertStringContainsString('use \StraussLatte\Latte\Strict;', $phpString);
    }
}
