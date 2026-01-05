<?php
namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * Class CleanupIntegrationTest
 * @package BrianHenryIE\Strauss\Tests\Integration
 * @coversNothing
 */
class CleanupIntegrationTest extends IntegrationTestCase
{

    /**
     * When `delete_vendor_packages` is true, the autoloader should be cleaned of files that are not needed.
     */
    public function testFilesAutoloaderCleaned()
    {
        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "symfony/polyfill-php80": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_",
      "delete_vendor_packages": true
    }
  }
}
EOD;
        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        assert(file_exists($this->testsWorkingDir . '/vendor/symfony/polyfill-php80/bootstrap.php'));

        $exitCode = $this->runStrauss();
        assert($exitCode === 0);

        $installedJsonFile = file_get_contents($this->testsWorkingDir .'vendor/composer/installed.json');
        $installedJson = json_decode($installedJsonFile, true);
        $entry = array_reduce($installedJson['packages'], function ($carry, $item) {
            if ($item['name'] === 'symfony/polyfill-php80') {
                return $item;
            }
            return $carry;
        }, null);
        $this->assertEmpty($entry['autoload'], json_encode($entry['autoload'], JSON_PRETTY_PRINT));

        $autoloadStaticPhp = file_get_contents($this->testsWorkingDir .'vendor/composer/autoload_static.php');
        $this->assertStringNotContainsString("__DIR__ . '/..' . '/symfony/polyfill-php80/bootstrap.php'", $autoloadStaticPhp);

        $this->assertFileDoesNotExist($this->testsWorkingDir .'vendor/composer/autoload_files.php');

        $autoloadFilesPhp = file_get_contents($this->testsWorkingDir .'vendor-prefixed/composer/autoload_files.php');
        $this->assertStringContainsString("\$vendorDir . '/symfony/polyfill-php80/bootstrap.php'", $autoloadFilesPhp);

        $newAutoloadFilesPhp = file_get_contents($this->testsWorkingDir .'vendor-prefixed/composer/autoload_files.php');
        $this->assertStringContainsString("/symfony/polyfill-php80/bootstrap.php'", $newAutoloadFilesPhp);
    }

    /**
     * Packages in `exclude_from_copy.packages` should NOT be deleted when `delete_vendor_packages` is true.
     * They are excluded from copying, so they should remain in vendor/ for use by non-prefixed code.
     */
    public function testExcludedPackagesNotDeletedWhenDeleteVendorPackagesEnabled(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "test/exclude-delete-bug",
  "require": {
    "psr/log": "^1.1",
    "psr/container": "^1.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Test\\",
      "exclude_from_copy": {
        "packages": ["psr/log"]
      },
      "delete_vendor_packages": true
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);
        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);
        exec('composer install');

        // Pre-condition: both packages exist before Strauss
        $this->assertDirectoryExists($this->testsWorkingDir . '/vendor/psr/log');
        $this->assertDirectoryExists($this->testsWorkingDir . '/vendor/psr/container');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        // CORE ASSERTION: Excluded package must NOT be deleted
        $this->assertDirectoryExists(
            $this->testsWorkingDir . '/vendor/psr/log',
            'Excluded package psr/log should NOT be deleted from vendor/'
        );

        // SANITY CHECK: Non-excluded package should still be deleted
        $this->assertDirectoryDoesNotExist(
            $this->testsWorkingDir . '/vendor/psr/container',
            'Non-excluded package psr/container should be deleted from vendor/'
        );
    }
}
