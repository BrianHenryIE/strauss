<?php

namespace BrianHenryIE\Strauss\Autoload;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

class DumpAutoloadFeatureTest extends IntegrationTestCase
{
    /**
     * I think what's been happening is that the vendor-prefixed autoloader also includes the autoload directives
     * in the root composer.json. When `files` are involved, they get `require`d twice.
     *
     * @param string $composerJsonString Contents of the composer.json file.
     * @param bool   $includeRootAutoload Whether the root autoload should be included in the vendor-prefixed autoloader.
     *
     * @dataProvider provider_fix_double_loading_of_files_autoloaders
     */
    public function test_fix_double_loading_of_files_autoloaders(string $composerJsonString, bool $includeRootAutoload): void
    {
        mkdir($this->testsWorkingDir . 'src');
        file_put_contents($this->testsWorkingDir . 'src/DumpAutoloadFeatureTest.php', '<?php // whatever');

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $vendorAutoloadFilesPhpString = file_get_contents($this->testsWorkingDir . 'vendor/composer/autoload_files.php');
        $this->assertStringContainsString('DumpAutoloadFeatureTest.php', $vendorAutoloadFilesPhpString);

        $vendorPrefixedAutoloadFilesPhpString = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/composer/autoload_files.php');
        if ($includeRootAutoload) {
            $this->assertStringContainsString('DumpAutoloadFeatureTest.php', $vendorPrefixedAutoloadFilesPhpString);
        } else {
            $this->assertStringNotContainsString('DumpAutoloadFeatureTest.php', $vendorPrefixedAutoloadFilesPhpString);
        }
    }

    /**
     * Data provider for test_fix_double_loading_of_files_autoloaders.
     */
    public static function provider_fix_double_loading_of_files_autoloaders(): array
    {
        $withoutRootAutoload = <<<'EOD'
{
    "name": "brianhenryie/dump-autoload-feature-test",
    "autoload": {
        "files": [
            "src/DumpAutoloadFeatureTest.php"
        ]
    },
    "require": {
        "symfony/deprecation-contracts": "*"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "BrianHenryIE\\Strauss\\",
            "target_directory": "vendor-prefixed",
            "delete_vendor_packages": true
        }
    }
}
EOD;

        $withRootAutoload = <<<'EOD'
{
    "name": "brianhenryie/dump-autoload-feature-test",
    "autoload": {
        "files": [
            "src/DumpAutoloadFeatureTest.php"
        ]
    },
    "require": {
        "symfony/deprecation-contracts": "*"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "BrianHenryIE\\Strauss\\",
            "target_directory": "vendor-prefixed",
            "delete_vendor_packages": true,
            "include_root_autoload": true
        }
    }
}
EOD;

        return [
            'withoutRootAutoload' => [$withoutRootAutoload, false],
            'withRootAutoload'    => [$withRootAutoload, true],
        ];
    }

    /**
     * Test the `include_root_autoload` option. Expect autoload classes in both the vendor and vendor-prefixed
     * autoloader if the option is set true, otherwise only in the vendor autoloader.
     *
     * @param string $composerJsonString  Contents of the composer.json file.
     * @param bool   $expectRootAutoload  Whether autoload classes are expected in the vendor-prefixed autoloader.
     *
     * @dataProvider provider_option_include_root_autoload
     */
    public function test_option_include_root_autoload(string $composerJsonString, bool $expectRootAutoload): void
    {
        mkdir($this->testsWorkingDir . 'src');

        $classContent = <<<'EOD'
<?php

namespace BrianHenryIE\Strauss;

class DumpAutoloadFeatureTest {}
EOD;

        file_put_contents($this->testsWorkingDir . 'src/DumpAutoloadFeatureTest.php', $classContent);

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $targetString = '\'BrianHenryIE\\\\Strauss\\\\\' => array($baseDir . \'/src\'),';
        $vendorAutoloadPsr4PhpString = file_get_contents($this->testsWorkingDir . 'vendor/composer/autoload_psr4.php');
        $vendorPrefixedAutoloadPsr4PhpString = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/composer/autoload_psr4.php');

        if ($expectRootAutoload) {
            $this->assertStringContainsString($targetString, $vendorAutoloadPsr4PhpString);
            $this->assertStringContainsString($targetString, $vendorPrefixedAutoloadPsr4PhpString);
        } else {
            $this->assertStringContainsString($targetString, $vendorAutoloadPsr4PhpString);
            $this->assertStringNotContainsString($targetString, $vendorPrefixedAutoloadPsr4PhpString);
        }
    }

    /**
     * Data provider for test_option_include_root_autoload.
     */
    public static function provider_option_include_root_autoload(): array
    {
        $rootAutoloadNotSet = <<<'EOD'
{
    "name": "brianhenryie/dump-autoload-feature-test",
    "autoload": {
        "psr-4": {
            "BrianHenryIE\\Strauss\\": "src/"
        }
    },
    "require": {
        "symfony/deprecation-contracts": "*"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "BrianHenryIE\\Strauss\\",
            "target_directory": "vendor-prefixed",
            "delete_vendor_packages": true
        }
    }
}
EOD;

        $rootAutoloadSetTrue = <<<'EOD'
{
    "name": "brianhenryie/dump-autoload-feature-test",
    "autoload": {
        "psr-4": {
            "BrianHenryIE\\Strauss\\": "src/"
        }
    },
    "require": {
        "symfony/deprecation-contracts": "*"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "BrianHenryIE\\Strauss\\",
            "target_directory": "vendor-prefixed",
            "delete_vendor_packages": true,
            "include_root_autoload": true
        }
    }
}
EOD;

        $rootAutoloadSetFalse = <<<'EOD'
{
    "name": "brianhenryie/dump-autoload-feature-test",
    "autoload": {
        "psr-4": {
            "BrianHenryIE\\Strauss\\": "src/"
        }
    },
    "require": {
        "symfony/deprecation-contracts": "*"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "BrianHenryIE\\Strauss\\",
            "target_directory": "vendor-prefixed",
            "delete_vendor_packages": true,
            "include_root_autoload": false
        }
    }
}
EOD;

        return [
            'rootAutoloadNotSet'   => [$rootAutoloadNotSet, false],
            'rootAutoloadSetTrue'  => [$rootAutoloadSetTrue, true],
            'rootAutoloadSetFalse' => [$rootAutoloadSetFalse, false],
        ];
    }
}
