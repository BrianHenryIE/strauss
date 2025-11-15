<?php

namespace BrianHenryIE\Strauss\Autoload;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;
use Composer\Autoload\AutoloadGenerator;

class DumpAutoloadFeatureTest extends IntegrationTestCase
{
    /**
     * I think what's been happening is that the vendor-prefixed autoloader also includes the autoload directives
     * in the root composer.json. When `files` are involved, they get `require`d twice.
     */
    public function test_fix_double_loading_of_files_autoloaders(): void
    {

        $composerJsonString = <<<'EOD'
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

        mkdir($this->testsWorkingDir . 'src');
        file_put_contents($this->testsWorkingDir . 'src/DumpAutoloadFeatureTest.php', '<?php // whatever');

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $vendorAutoloadFilesPhpString = file_get_contents($this->testsWorkingDir . 'vendor/composer/autoload_files.php');
        $this->assertStringContainsString('DumpAutoloadFeatureTest.php', $vendorAutoloadFilesPhpString);

        $vendorPrefixedAutoloadFilesPhpString = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/composer/autoload_files.php');
        $this->assertStringNotContainsString('DumpAutoloadFeatureTest.php', $vendorPrefixedAutoloadFilesPhpString);
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
        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $vendorAutoloadFilesPhpString = file_get_contents($this->testsWorkingDir . '/vendor-prefixed/composer/autoload_classmap.php');
        $this->assertStringContainsString('BrianHenryIE\\\\Strauss\\\\WP\\\\MCP\\\\Abilities\\\\DiscoverAbilitiesAbility', $vendorAutoloadFilesPhpString);

        exec('php -r "include __DIR__ . \'/vendor-prefixed/autoload.php\'; require __DIR__ . \'/vendor-prefixed/wordpress/mcp-adapter/mcp-adapter.php\';" 2>&1', $output, $result_code);
        $outputString = implode(PHP_EOL, $output);

        $this->assertEquals(0, $result_code, $outputString);
    }
}
