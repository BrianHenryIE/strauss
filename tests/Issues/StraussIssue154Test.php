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

    /**
     * @see https://github.com/BrianHenryIE/strauss/pull/157#issuecomment-2757377363
     */
    public function test_parameter()
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

        $phpString = file_get_contents($this->testsWorkingDir .'vendor-prefixed/latte/latte/src/Latte/Macros/BlockMacros.php');

        $this->assertStringNotContainsString('public static function install(Latte\Compiler $compiler)', $phpString);
        $this->assertStringNotContainsString('public static function install(StraussLatte\Latte\Compiler $compiler)', $phpString);
        $this->assertStringContainsString('public static function install(\StraussLatte\Latte\Compiler $compiler)', $phpString);
    }

    /**
     * @see https://github.com/BrianHenryIE/strauss/pull/157#issuecomment-2757377363
     */
    public function test_constant()
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

        $phpString = file_get_contents($this->testsWorkingDir .'vendor-prefixed/latte/latte/src/Latte/Macros/BlockMacros.php');

        $this->assertStringNotContainsString('((string) $node->context[1], Latte\Compiler::CONTEXT_HTML_ATTRIBUTE))', $phpString);
        $this->assertStringNotContainsString('((string) $node->context[1], StraussLatte\Latte\Compiler::CONTEXT_HTML_ATTRIBUTE))', $phpString);
        $this->assertStringContainsString('((string) $node->context[1], \StraussLatte\Latte\Compiler::CONTEXT_HTML_ATTRIBUTE))', $phpString);
    }

    /**
     * @see https://github.com/BrianHenryIE/strauss/pull/157#issuecomment-2757461258
     */
    public function test_class_prefix(): void
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

        $phpString = file_get_contents($this->testsWorkingDir .'vendor-prefixed/latte/latte/src/compatibility.php');

        $this->assertStringNotContainsString('class_alias(HtmlStringable::class, StraussLatte_IHtmlString::class);', $phpString);
        $this->assertStringContainsString('class_alias(HtmlStringable::class, IHtmlString::class);', $phpString);
    }

    /**
     * @see https://github.com/BrianHenryIE/strauss/pull/157#issuecomment-2757461258
     */
    public function test_multiple_namespaces(): void
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

        $phpString = file_get_contents($this->testsWorkingDir .'vendor-prefixed/latte/latte/src/compatibility.php');

        $this->assertStringNotContainsString('namespace Latte {', $phpString);
        $this->assertStringNotContainsString('namespace Latte\Runtime {', $phpString);
        $this->assertStringContainsString('namespace StraussLatte\Latte {', $phpString);
        $this->assertStringContainsString('namespace StraussLatte\Latte\Runtime {', $phpString);
    }
}
