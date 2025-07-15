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

        $autoloadFilesPhp = file_get_contents($this->testsWorkingDir .'vendor/composer/autoload_files.php');
        $this->assertStringNotContainsString("\$vendorDir . '/symfony/polyfill-php80/bootstrap.php'", $autoloadFilesPhp);

        $newAutoloadFilesPhp = file_get_contents($this->testsWorkingDir .'vendor-prefixed/composer/autoload_files.php');
        $this->assertStringContainsString("/symfony/polyfill-php80/bootstrap.php'", $newAutoloadFilesPhp);
    }
}
