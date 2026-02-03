<?php
/**
 * When using Strauss to process the DomPDF package, not all files are being copied over.
 * Specifically, the VERSION file is missing, causing DomPDF to fail.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/215
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue215Test extends IntegrationTestCase
{
    public function test_all_files_are_copied()
    {
        $packageComposerJson = <<<'EOD'
{   
	"name": "test/package-with-version-file",
    "extra": {
        "strauss": {
            "namespace_prefix": "WebAppCore\\",
            "classmap_prefix": "WebAppCore_",
            "constant_prefix": "WEB_APP_CORE_",
            "exclude_from_copy": {
				"packages": [
			        "masterminds/html5",
                    "dompdf/php-font-lib",
		            "dompdf/php-svg-lib"
	            ]
			}
        }
    },
    "require": {
        "dompdf/dompdf": "^3.1"
    }
}
EOD;
        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $packageComposerJson);

        chdir($this->testsWorkingDir);
        exec('composer install');

        $expectedFiles = array_map(
            fn(string $filePath) => str_replace($this->testsWorkingDir . '/vendor/', '', $filePath),
            glob($this->testsWorkingDir . '/vendor/dompdf/dompdf/*')
        );

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $copiedFiles = array_map(
            fn(string $filePath) => str_replace($this->testsWorkingDir . '/vendor-prefixed/', '', $filePath),
            glob($this->testsWorkingDir . '/vendor-prefixed/dompdf/dompdf/*')
        );

        $missingFiles = array_diff($expectedFiles, $copiedFiles);

        $this->assertEmpty($missingFiles);
    }
}
