<?php
/**
 * After files have been copied and prefixed, we use Composer's tools to generate the autoload files.
 */

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @covers \BrianHenryIE\Strauss\Pipeline\Autoload\DumpAutoload
 */
class DumpAutoloadFeatureTest extends IntegrationTestCase
{
    /**
     * TODO: Ideally, a test where some no-dev packages are shared with dev packages and all autoloader types covered.
     */
    public function testDumpAutoload(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/testdumpautoload",
  "require": {
    "symfony/polyfill-ctype": "*"
  },
  "require-dev": {
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Dump_Autoload\\",
      "classmap_prefix": "Dump_Autoload_",
      "delete_vendor_packages": true
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert($exitCode === 0, $output);

        exec('composer dump-autoload');

        $this->assertFileDoesNotExist($this->testsWorkingDir . 'vendor/composer/autoload_files.php');
//        $vendorAutoloadFilesPhpString = file_get_contents($this->testsWorkingDir . 'vendor/composer/autoload_files.php');
        $vendorPrefixedAutoloadFilesPhpString = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/composer/autoload_files.php');

        $this->assertStringContainsString('symfony/polyfill-ctype', $vendorPrefixedAutoloadFilesPhpString);
//        $this->assertStringNotContainsString('symfony/polyfill-ctype', $vendorAutoloadFilesPhpString);
    }
}
