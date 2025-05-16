<?php
/**
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/179
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue179Test extends IntegrationTestCase
{
    public function test_issue_179()
    {
        $this->markTestSkippedOnPhpVersion('8.1.0', ">=");

        $composerJsonString = <<<'EOD'
{
    "repositories": [
    	{
			"type": "vcs",
			"url": "https://github.com/jcvignoli/imdbGraphQLPHP",
			"no-api": true
    	}
    ],
    "config": {
        "platform": {
            "php": "8.1"
        }
    },
    "require": {
        "php": ">=8.1",
        "duck7000/imdb-graphql-php": "dev-jcv",
        "twbs/bootstrap": "@stable",
        "monolog/monolog": "@stable",
        "psr/log": "1.1.0"
    },
	"extra": {
	    "strauss": {
	        "target_directory": "vendor-prefixed",
	        "namespace_prefix": "Lumiere\\Vendor\\",
	        "classmap_prefix": "Lumiere_",
	        "packages": [
	                "monolog/monolog",
	                "duck7000/imdb-graphql-php"
	        ],
	        "update_call_sites": true,
	        "delete_vendor_packages": true
	    }
	}
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');
        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $exitCode = $this->runStrauss($output, 'include-autoloader');
        assert(0 === $exitCode, $output);

        exec('composer install');
        $exitCode = $this->runStrauss($output);

        $this->assertEquals(0, $exitCode, $output);
    }
}
