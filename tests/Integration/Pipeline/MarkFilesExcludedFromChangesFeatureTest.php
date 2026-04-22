<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @coversNothing
 * @see MarkFilesExcludedFromChanges
 */
class MarkFilesExcludedFromChangesFeatureTest extends IntegrationTestCase
{

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
            "exclude_files_from_update": {
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
    }
}
