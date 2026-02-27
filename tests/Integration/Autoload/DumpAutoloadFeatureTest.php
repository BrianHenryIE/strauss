<?php

namespace BrianHenryIE\Strauss\Autoload;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\IntegrationTestCase;
use BrianHenryIE\Strauss\Pipeline\Autoload\DumpAutoload;
use BrianHenryIE\Strauss\Pipeline\FileEnumerator;
use BrianHenryIE\Strauss\Pipeline\Prefixer;
use Composer\Autoload\AutoloadGenerator;
use Composer\Factory;
use Composer\IO\NullIO;
use League\Flysystem\Local\LocalFilesystemAdapter;

class DumpAutoloadFeatureTest extends IntegrationTestCase
{
    /**
     * @dataProvider provider_optimize_autoloader_for_prefixed_autoload_real
     */
    public function test_optimize_autoloader_for_prefixed_autoload_real(string $composerJsonString, bool $expectAuthoritative): void
    {
        try {
            file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);
            chdir($this->testsWorkingDir);
            exec('composer install', $output, $exitCode);
            $this->assertEquals(0, $exitCode, implode(PHP_EOL, $output));
            @mkdir($this->testsWorkingDir . 'vendor-prefixed/composer', 0777, true);
            $sourceComposerDir = $this->testsWorkingDir . 'vendor/composer';
            $targetComposerDir = $this->testsWorkingDir . 'vendor-prefixed/composer';
            foreach (scandir($sourceComposerDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $sourcePath = $sourceComposerDir . '/' . $entry;
                $targetPath = $targetComposerDir . '/' . $entry;
                if (is_file($sourcePath)) {
                    copy($sourcePath, $targetPath);
                }
            }
            copy($this->testsWorkingDir . 'vendor/autoload.php', $this->testsWorkingDir . 'vendor-prefixed/autoload.php');
            $composer = Factory::create(new NullIO(), $this->testsWorkingDir . 'composer.json');
            $config = new StraussConfig($composer);
            $psrLogPackage = ComposerPackage::fromFile($this->testsWorkingDir . 'vendor/psr/log/composer.json');
            $config->setPackagesToCopy(['psr/log' => $psrLogPackage]);
            $config->setPackagesToPrefix(['psr/log' => $psrLogPackage]);
            $filesystem = new FileSystem(new \League\Flysystem\Filesystem(new LocalFilesystemAdapter('/')), $this->testsWorkingDir);
            $dumpAutoload = new DumpAutoload($config, $filesystem, $this->logger, new Prefixer($config, $filesystem, $this->logger), new FileEnumerator($config, $filesystem, $this->logger));
            $dumpAutoload->generatedPrefixedAutoloader();
            $autoloadRealPath = $this->testsWorkingDir . 'vendor-prefixed/composer/autoload_real.php';
            $this->assertFileExists($autoloadRealPath);
            $autoloadRealPhpString = file_get_contents($autoloadRealPath);
            if ($expectAuthoritative) {
                $this->assertStringContainsString('setClassMapAuthoritative(true)', $autoloadRealPhpString);
            } else {
                $this->assertStringNotContainsString('setClassMapAuthoritative(true)', $autoloadRealPhpString);
            }
        } finally {
            chdir($this->projectDir);
        }
    }

    /**
     * @return array<string, array{0:string, 1:bool}>
     */
    public static function provider_optimize_autoloader_for_prefixed_autoload_real(): array
    {
        $defaultOptimize = <<<'EOD'
{
    "name": "brianhenryie/dump-autoload-feature-test-optimize-default",
    "require": {
        "psr/log": "*"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "BrianHenryIE\\Strauss\\",
            "classmap_prefix": "BrianHenryIE_Strauss_",
            "target_directory": "vendor-prefixed",
            "delete_vendor_packages": true
        }
    }
}
EOD;
        $disableOptimize = <<<'EOD'
{
    "name": "brianhenryie/dump-autoload-feature-test-optimize-disabled",
    "require": {
        "psr/log": "*"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "BrianHenryIE\\Strauss\\",
            "classmap_prefix": "BrianHenryIE_Strauss_",
            "target_directory": "vendor-prefixed",
            "delete_vendor_packages": true,
            "optimize_autoloader": false
        }
    }
}
EOD;
        return [
            'key_omitted_defaults_to_optimized' => [$defaultOptimize, true],
            'explicit_false_disables_authoritative' => [$disableOptimize, false],
        ];
    }

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

        exec('composer install');

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
