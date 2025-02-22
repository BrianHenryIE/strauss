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
  "name": "brianhenryie/testcomposerdumpautoloadontargetdirectory",
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

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        exec('composer dump-autoload');

        $vendorInstalledJsonStringAfter = file_get_contents($this->testsWorkingDir . 'vendor/composer/installed.json');
        $vendorPrefixedInstalledJsonPsr4PhpStringAfter = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/composer/installed.json');

        $this->assertStringContainsString('BrianHenryIE\\\\Strauss\\\\chillerlan\\\\Settings\\\\', $vendorPrefixedInstalledJsonPsr4PhpStringAfter);
        $this->assertStringNotContainsString('"chillerlan\\\\Settings\\\\', $vendorInstalledJsonStringAfter);
    }

    /**
     */
    public function testComposerDumpAutoloadOnTargetDirectoryIsVendorDir(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/testcomposerdumpautoloadontargetdirectoryisvendordir",
  "require": {
    "chillerlan/php-qrcode": "^4"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "target_directory": "vendor"
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $vendorInstalledJsonStringAfter = file_get_contents($this->testsWorkingDir . 'vendor/composer/installed.json');

        $this->assertStringContainsString('BrianHenryIE\\\\Strauss\\\\chillerlan\\\\Settings\\\\', $vendorInstalledJsonStringAfter);
        $this->assertStringNotContainsString('"chillerlan\\\\Settings\\\\', $vendorInstalledJsonStringAfter);
    }

    public function testComposerDumpAutoloadWithDeleteFalse(): void
    {
        $composerJsonString = <<<'EOD'
{
    "name": "brianhenryie/testcomposerdumpautoloadwithdeletefalse",
    "require": {
        "chillerlan/php-qrcode": "^4"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "BrianHenryIE\\Strauss\\",
            "delete_vendor_packages": false,
            "delete_vendor_files": false,
            "target_directory": "vendor-prefixed"
        }
    }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        exec('composer dump-autoload');

        $vendorInstalledJsonStringAfter = file_get_contents($this->testsWorkingDir . 'vendor/composer/installed.json');
        $vendorPrefixedInstalledJsonPsr4PhpStringAfter = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/composer/installed.json');

        $this->assertStringContainsString('BrianHenryIE\\\\Strauss\\\\chillerlan\\\\Settings\\\\', $vendorPrefixedInstalledJsonPsr4PhpStringAfter);
        $this->assertStringNotContainsString('BrianHenryIE\\\\Strauss\\\\chillerlan\\\\Settings\\\\', $vendorInstalledJsonStringAfter);
        $this->assertStringContainsString('"chillerlan\\\\Settings\\\\', $vendorInstalledJsonStringAfter);
    }
}
