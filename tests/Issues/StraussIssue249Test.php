<?php
/**
 * [warning] Package directory unexpectedly DOES NOT exist: /path/to/vendor-prefixed/freemius/wordpress-sdk
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/249
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue249Test extends IntegrationTestCase
{

    public function test_return_type_double_prefixed(): void
    {

        $composerJsonString = <<<'EOD'
{   
    "require": {
      "freemius/wordpress-sdk": "^2.13"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "PrintusSmartPrintTiming\\",
            "classmap_prefix": "PrintusSmartPrintTiming_",
            "constant_prefix": "PSPT_",
            "exclude_from_copy": {
                "packages": [
                  "freemius/wordpress-sdk"
                ]
            }
        }
    }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');
        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $this->assertStringNotContainsString('Package directory unexpectedly DOES NOT exist', $output);

        $vendorPrefixedInstalledJson = $this->getFileSystem()->read($this->testsWorkingDir . 'vendor-prefixed/composer/installed.json');
        $vendorPrefixedPackageNames = $this->extractPackageNamesFromInstalledJson($vendorPrefixedInstalledJson);
        $this->assertNotContains('freemius/wordpress-sdk', $vendorPrefixedPackageNames);

        $vendorInstalledJson = $this->getFileSystem()->read($this->testsWorkingDir . 'vendor/composer/installed.json');
        $vendorPackageNames = $this->extractPackageNamesFromInstalledJson($vendorInstalledJson);
        $this->assertContains('freemius/wordpress-sdk', $vendorPackageNames);
    }

    /**
     * @return string[]
     */
    private function extractPackageNamesFromInstalledJson(string $installedJson): array
    {
        $installedJsonArray = json_decode($installedJson, true);

        $this->assertIsArray($installedJsonArray, 'installed.json should decode to an array');
        $this->assertArrayHasKey('packages', $installedJsonArray, 'installed.json should contain packages');
        $this->assertIsArray($installedJsonArray['packages']);

        return array_values(array_filter(array_map(
            static fn(array $package): ?string => $package['name'] ?? null,
            $installedJsonArray['packages']
        )));
    }
}
