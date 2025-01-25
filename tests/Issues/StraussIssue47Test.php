<?php
/**
 * When the namespace being replaced is a substring of the prefix, the order of replacements
 * is important, otherwise the replacement is performed twice.
 *
 * @see \BrianHenryIE\Strauss\Pipeline\Prefixer::replaceInString()
 * @see asort()
 *
 * @see https://core.trac.wordpress.org/ticket/42670
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue47Test extends IntegrationTestCase
{

    /*
     * The proper failing test.
     */
    public function test_double_namespace()
    {

        $composerJsonString = <<<'EOD'
{
    "name": "brianhenryie/double-namespace-47",
    "minimum-stability": "dev",
    "repositories": {
        "dragon-public/framework": {
            "type": "git",
            "url": "https://gitlab.com/dragon-public/framework/"
        }
    },
    "require": {
        "dragon-public/framework": "1.3.0"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "Dragon\\Dependencies\\",
            "target_directory": "/strauss/",
            "classmap_prefix": "Dragon_Dependencies_"
        }
    },
    "provide": {
        "guzzlehttp/guzzle": "*",
        "ramsey/uuid": "*",
        "illuminate/config": "*",
        "illuminate/container": "*",
        "illuminate/database": "*",
        "illuminate/filesystem": "*",
        "illuminate/translation": "*",
        "illuminate/validation": "*",
        "illuminate/pagination": "*",
        "illuminate/view": "*",
        "league/flysystem": "*",
        "symfony/var-dumper": "*",
        "doctrine/dbal": "*",
        "psr/log": "*",
        "spatie/guzzle-rate-limiter-middleware": "*"
    }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $result = $this->runStrauss();

        // 0 for no errors.
        self::assertNotEquals(1, $result);

        $php_string = file_get_contents($this->testsWorkingDir . 'strauss/dragon-public/framework/src/Form/TextArea.php');

        self::assertStringNotContainsString('namespace Dragon\Dependencies\Dragon\Dependencies\Dragon\Form;', $php_string);
        self::assertStringContainsString('namespace Dragon\Dependencies\Dragon\Form;', $php_string);
    }

    /*
     * Exclude all other packages, so step debugging has less noise.
     */
    public function test_double_namespace_dont_copy_dependencies()
    {
        $composerJsonString = <<<'EOD'
{
    "name": "brianhenryie/double-namespace-47",
    "minimum-stability": "dev",
    "repositories": {
        "dragon-public/framework": {
            "type": "git",
            "url": "https://gitlab.com/dragon-public/framework/"
        }
    },
    "require": {
        "dragon-public/framework": "*"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "Dragon\\Dependencies\\",
            "target_directory": "/strauss/",
            "classmap_prefix": "Dragon_Dependencies_",
            "exclude_from_copy": {
                "packages": [
                    "guzzlehttp/guzzle",
                    "ramsey/uuid",
                    "illuminate/database",
                    "illuminate/filesystem",
                    "illuminate/translation",
                    "illuminate/validation",
                    "illuminate/pagination",
                    "symfony/var-dumper",
                    "doctrine/dbal"
                ]
            },
            "exclude_from_prefix": {
                "namespaces": [
                    "voku\\",
                    "Symfony\\",
                    "Ramsey\\",
                    "Illuminate\\",
                    "GuzzleHttp\\",
                    "Egulias\\",
                    "Doctrine\\",
                    "Carbon",
                    "Brick\\"
                ]
            }
        }
    },
    "provide": {
        "guzzlehttp/guzzle": "*",
        "ramsey/uuid": "*",
        "illuminate/config": "*",
        "illuminate/container": "*",
        "illuminate/database": "*",
        "illuminate/filesystem": "*",
        "illuminate/translation": "*",
        "illuminate/validation": "*",
        "illuminate/pagination": "*",
        "illuminate/view": "*",
        "league/flysystem": "*",
        "symfony/var-dumper": "*",
        "doctrine/dbal": "*",
        "psr/log": "*",
        "spatie/guzzle-rate-limiter-middleware": "*"
    }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $result = $this->runStrauss();

        // 0 for no errors.
        self::assertNotEquals(1, $result);

        $php_string = file_get_contents($this->testsWorkingDir . 'strauss/dragon-public/framework/src/Form/TextArea.php');

        self::assertStringNotContainsString('namespace Dragon\Dependencies\Dragon\Dependencies\Dragon\Form;', $php_string);
        self::assertStringContainsString('namespace Dragon\Dependencies\Dragon\Form;', $php_string);
    }

    /**
     * Test only one file. This did not fail.
     */
    public function test_double_namespace_only_file_copied()
    {

        $composerJsonString = <<<'EOD'
{
    "name": "brianhenryie/double-namespace-47",
    "minimum-stability": "dev",
    "repositories": {
        "dragon-public/framework": {
            "type": "git",
            "url": "https://gitlab.com/dragon-public/framework/"
        }
    },
    "require": {
        "dragon-public/framework": "*"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "Dragon\\Dependencies\\",
            "target_directory": "/strauss/",
            "classmap_prefix": "Dragon_Dependencies_",
            "exclude_from_copy": {
                "file_patterns": [
                    "/^((?!Form\\/TextArea.php$).)*$/"
                ]
            }
        }
    },
    "provide": {
        "guzzlehttp/guzzle": "*",
        "ramsey/uuid": "*",
        "illuminate/config": "*",
        "illuminate/container": "*",
        "illuminate/database": "*",
        "illuminate/filesystem": "*",
        "illuminate/translation": "*",
        "illuminate/validation": "*",
        "illuminate/pagination": "*",
        "illuminate/view": "*",
        "league/flysystem": "*",
        "symfony/var-dumper": "*",
        "doctrine/dbal": "*",
        "psr/log": "*",
        "spatie/guzzle-rate-limiter-middleware": "*"
    }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $result = $this->runStrauss();

        // 0 for no errors.
        self::assertNotEquals(1, $result);

        $php_string = file_get_contents($this->testsWorkingDir . 'strauss/dragon-public/framework/src/Form/TextArea.php');

        self::assertStringNotContainsString('namespace Dragon\Dependencies\Dragon\Dependencies\Dragon\Form;', $php_string);
        self::assertStringContainsString('namespace Dragon\Dependencies\Dragon\Form;', $php_string);
    }
}
