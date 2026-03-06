<?php

namespace BrianHenryIE\Strauss\Autoload;

use BrianHenryIE\Strauss\IntegrationTestCase;
use BrianHenryIE\Strauss\Pipeline\Autoload\DumpAutoload;
use Composer\Autoload\AutoloadGenerator;

/**
 * @see DumpAutoload
 */
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
        $this->getFileSystem()->write($this->testsWorkingDir . 'src/DumpAutoloadFeatureTest.php', '<?php // whatever');

        $this->getFileSystem()->write($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install', $composerInstallOutput, $composerInstallExitCode);
        $this->assertEquals(0, $composerInstallExitCode, implode(PHP_EOL, $composerInstallOutput));

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $vendorAutoloadFilesPhpString = $this->getFileSystem()->read($this->testsWorkingDir . 'vendor/composer/autoload_files.php');
        $this->assertStringContainsString('DumpAutoloadFeatureTest.php', $vendorAutoloadFilesPhpString);

        $vendorPrefixedAutoloadFilesPhpString = $this->getFileSystem()->read($this->testsWorkingDir . 'vendor-prefixed/composer/autoload_files.php');
        if ($includeRootAutoload) {
            $this->assertStringContainsString('DumpAutoloadFeatureTest.php', $vendorPrefixedAutoloadFilesPhpString);
        } else {
            $this->assertStringNotContainsString('DumpAutoloadFeatureTest.php', $vendorPrefixedAutoloadFilesPhpString);
        }
    }

    /**
     * Data provider for test_fix_double_loading_of_files_autoloaders.
     *
     * @return array<string, array{0:string, 1:bool}>
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

        $this->getFileSystem()->write($this->testsWorkingDir . 'src/DumpAutoloadFeatureTest.php', $classContent);

        $this->getFileSystem()->write($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $targetString = '\'BrianHenryIE\\\\Strauss\\\\\' => array($baseDir . \'/src\'),';
        $vendorAutoloadPsr4PhpString = $this->getFileSystem()->read($this->testsWorkingDir . 'vendor/composer/autoload_psr4.php');
        $vendorPrefixedAutoloadPsr4PhpString = $this->getFileSystem()->read($this->testsWorkingDir . 'vendor-prefixed/composer/autoload_psr4.php');

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
     *
     * @return array<string, array{0:string, 1:bool}>
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

    /**
     * vendor-prefixed/autoload* with setAuthoritativeClassmap aren't including the classes in classmap for indirect dependency
     *
     * @see vendor/composer/composer/src/Composer/Autoload/AutoloadGenerator.php
     * @see AutoloadGenerator::filterPackageMap()
     *
     * Composer only includes autolaoders for packages that are required by another package. Typically this is the
     * root package, but when only a subset of packages are set for prefixing, there is no "parent" package requiring
     * them. Let's fix that.
     */
    public function test_check_prefixed_autoloader_indirect(): void
    {
        $composerJsonString = <<<'EOD'
{
    "name": "brianhenryie/dump-autoload-feature-test-2",
    "repositories": {
	  "newfold": {
		"type": "composer",
		"url": "https://newfold-labs.github.io/satis/",
		"only": [
			"newfold-labs/*"
		]
      }
    },
    "config": {
        "allow-plugins": {
            "dealerdirect/phpcodesniffer-composer-installer": true
        }
    },
    "require": {
        "newfold-labs/wp-module-mcp": "*"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "BrianHenryIE\\Strauss\\",
            "target_directory": "vendor-prefixed",
            "packages": [
                "wordpress/mcp-adapter"
            ],
            "delete_vendor_packages": true,
	        "exclude_from_copy": {
	          "file_patterns": [
	            "wordpress/mcp-adapter/.github",
	            "wordpress/mcp-adapter/docs",
	            "wordpress/mcp-adapter/tests",
	            "wordpress/mcp-adapter/CONTRIBUTING.md",
	            "wordpress/mcp-adapter/phpcs.xml.dist",
	            "wordpress/mcp-adapter/phpunit.xml.dist",
	            "wordpress/mcp-adapter/README-INITIAL.md",
	            "wordpress/mcp-adapter/phpstan.neon.dist"
	          ]
	        }
        }
    }
}
EOD;
        $this->getFileSystem()->write($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $vendorAutoloadFilesPhpString = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/composer/autoload_classmap.php');
        $this->assertStringContainsString('BrianHenryIE\\\\Strauss\\\\WP\\\\MCP\\\\Abilities\\\\DiscoverAbilitiesAbility', $vendorAutoloadFilesPhpString);

        exec('php -r "include __DIR__ . \'/vendor-prefixed/autoload.php\'; require __DIR__ . \'/vendor-prefixed/wordpress/mcp-adapter/mcp-adapter.php\';" 2>&1', $output, $result_code);
        $outputString = implode(PHP_EOL, $output);

        $this->assertEquals(0, $result_code, $outputString);
    }
}
