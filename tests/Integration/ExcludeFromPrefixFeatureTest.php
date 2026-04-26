<?php
// file_patterns

namespace BrianHenryIE\Strauss;

class ExcludeFromPrefixFeatureTest extends IntegrationTestCase
{

    public function test_exclude_from_prefix_file_patterns(): void
    {
        $composerJsonString = <<<'EOD'
{
    "name": "strauss/exclude-from-prefix",
    "require": {
        "lucatume/di52": "4.0.1",
        "psr/container": "*"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "Strauss\\ExcludeFromPrefixTest\\",
            "exclude_from_prefix": {
                "file_patterns": [
                    "/^psr.*$/"
                ]
            }
        }
    }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert($exitCode === 0, $output);

        $psrContainerPhpString = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/psr/container/src/ContainerInterface.php');
        $this->assertStringNotContainsString('namespace Strauss\ExcludeFromPrefixTest\Psr\Container;', $psrContainerPhpString);
        $this->assertStringContainsString('namespace Psr\Container;', $psrContainerPhpString);

        $di52ContainerPhpString = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/lucatume/di52/src/Container.php');
        $this->assertStringNotContainsString('use Strauss\ExcludeFromPrefixTest\Psr\Container\ContainerInterface;', $di52ContainerPhpString);
        $this->assertStringContainsString('use Psr\Container\ContainerInterface;', $di52ContainerPhpString);
    }

    public function test_namespace_excluded(): void
    {
        $this->markTestSkippedOnPhpVersionEqualOrAbove('8.5.0');

        $packageComposerJson = <<<'EOD'
{
	"name": "test/namespaced-files-not-in-autoloader",
	 "require": {
        "art4/requests-psr18-adapter": "1.3.0"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "BrianHenryIE\\Strauss\\",
            "exclude_from_prefix": {
		      "namespaces": [
		        "WpOrg\\Requests"
		      ]
		    }
        }
    }
}
EOD;
        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $packageComposerJson);

        chdir($this->testsWorkingDir);
        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $php_string = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/art4/requests-psr18-adapter/v1-compat/autoload.php');

        $this->assertStringContainsString("class_exists('WpOrg\\Requests\\Requests')", $php_string);
    }

    public function test_ClassLoader(): void
    {
        $composerJsonString = <<<'EOD'
{
    "name": "strauss/exclude-from-prefix",
    "require": {
        "composer/composer": "2.9.7"
    },
    "provide": {
        "composer/ca-bundle": "*",
        "composer/class-map-generator": "*",
        "composer/metadata-minifier": "*",
        "composer/pcre": "*",
        "composer/semver": "*",
        "composer/spdx-licenses": "*",
        "composer/xdebug-handler": "*",
        "justinrainbow/json-schema": "*",
        "marc-mabe/php-enum": "*",
        "psr/container": "*",
        "psr/log": "*",
        "react/promise": "*",
        "seld/jsonlint": "*",
        "seld/phar-utils": "*",
        "seld/signal-handler": "*",
        "symfony/console": "*",
        "symfony/deprecation-contracts": "*",
        "symfony/filesystem": "*",
        "symfony/finder": "*",
        "symfony/polyfill-ctype": "*",
        "symfony/polyfill-intl-grapheme": "*",
        "symfony/polyfill-intl-normalizer": "*",
        "symfony/polyfill-mbstring": "*",
        "symfony/polyfill-php73": "*",
        "symfony/polyfill-php80": "*",
        "symfony/polyfill-php81": "*",
        "symfony/polyfill-php84": "*",
        "symfony/process": "*",
        "symfony/service-contracts": "*",
        "symfony/string": "*"
    },
    "extra": {
        "strauss": {
            "target_directory": "vendor",
            "namespace_prefix": "BrianHenryIE\\Strauss\\Vendor\\",
            "exclude_from_prefix": {
                "file_patterns": [
                    "#ClassLoader.php#"
                ]
            }
        }
    }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert($exitCode === 0, $output);

        $phpString = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor/composer/composer/src/Composer/Autoload/ClassLoader.php');
        $this->assertStringNotContainsString('namespace BrianHenryIE\\Strauss\\Vendor\\Composer\\Autoload;', $phpString);
        $this->assertStringContainsString('namespace Composer\\Autoload;', $phpString);

        // vendor/composer/composer/src/Composer/Autoload/ClassMapGenerator.php
        $phpString = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor/composer/composer/src/Composer/Autoload/ClassMapGenerator.php');
        $this->assertStringContainsString('namespace BrianHenryIE\\Strauss\\Vendor\\Composer\\Autoload;', $phpString);
        $this->assertStringNotContainsString('namespace Composer\\Autoload;', $phpString);
    }
}
