<?php

namespace BrianHenryIE\Strauss\Pipeline\Cleanup;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\Cleanup\InstalledJson
 */
class InstalledJsonIntegrationTest extends IntegrationTestCase
{

    /**
     * When {@see InstalledJson::cleanupVendorInstalledJson()} is run, it changes the relative paths to the packages.
     * When `composer dump-autoload` is then run, it does not include any files that are outside the true `vendor` directory
     */
    public function testComposerDumpAutoloadOnTargetDirectory(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/autoloadstaticintegrationtest",
  "require": {
    "chillerlan/php-qrcode": "^4"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "delete_vendor_packages": true
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $this->runStrauss();

        exec('composer dump-autoload');

        $vendorInstalledJsonStringAfter = file_get_contents($this->testsWorkingDir . 'vendor/composer/installed.json');
        $vendorPrefixedInstalledJsonPsr4PhpStringAfter = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/composer/installed.json');

        $this->assertStringContainsString('BrianHenryIE\\\\Strauss\\\\chillerlan\\\\Settings\\\\', $vendorPrefixedInstalledJsonPsr4PhpStringAfter);
        $this->assertStringNotContainsString('"chillerlan\\\\Settings\\\\', $vendorInstalledJsonStringAfter);
    }
}
