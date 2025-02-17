<?php

namespace BrianHenryIE\Strauss\Tests\Integration\Cleanup;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

class AutoloadStaticIntegrationTest extends IntegrationTestCase
{
    public function tearDown(): void
    {
        /**
         * Smoke test.
         *
         * `php -r "require_once 'vendor-prefixed/autoload.php'; require_once 'vendor/autoload.php';"`
         */
        exec('php -r "require_once \'vendor-prefixed/autoload.php\'; require_once \'vendor/autoload.php\';"', $output);
        $output = implode(PHP_EOL, $output);

        $this->assertEmpty($output, $output);

        parent::tearDown();
    }

    public function test_happy_path(): void
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

        $autoloadStaticPhpStringBefore = file_get_contents($this->testsWorkingDir . 'vendor/composer/autoload_static.php');

        $this->runStrauss();

        $autoloadStaticPhpStringAfter = file_get_contents($this->testsWorkingDir . 'vendor/composer/autoload_static.php');

        $this->markTestIncomplete();

        $this->assertStringContainsString('autoload_aliases.php', $autoloadStaticPhpStringAfter);
        $this->assertStringNotContainsString('autoload_aliases.php', $autoloadStaticPhpStringAfter);
    }

    /**
     * @coversNothing
     */
    public function testFilesAutoloader(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/autoloadstaticintegrationtest",
  "require": {
    "ralouphie/getallheaders": "*"
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

        $autoloadStaticPhpStringAfter = file_get_contents($this->testsWorkingDir . 'vendor/composer/autoload_static.php');

        $this->assertStringNotContainsString('ralouphie/getallheaders/src/getallheaders.php', $autoloadStaticPhpStringAfter);
    }
}
