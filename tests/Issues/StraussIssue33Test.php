<?php
/**
 *
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Pipeline\Prefixer;
use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue33Test extends IntegrationTestCase
{

    /**
     */
    public function test_backtrack_limit_exhausted()
    {
        if (version_compare(phpversion(), '8.1', '>=')) {
            $this->markTestSkipped("Package specified for test is not PHP 8.1 compatible. Running tests under PHP " . phpversion());
        }

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss-backtrack-limit-exhausted",
  "minimum-stability": "dev",
  "require": {
    "afragen/wp-dependency-installer": "^3.1",
    "mpdf/mpdf": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss_Backtrack_Limit_Exhausted\\",
      "target_directory": "/strauss/",
      "classmap_prefix": "BH_Strauss_Backtrack_Limit_Exhausted_"
    }
  }
}

EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);

        $this->assertEquals(0, $exitCode, $output);
    }



    /**
     *
     */
    public function test_unit_backtrack_limit_exhausted()
    {

        $contents = file_get_contents(__DIR__.'/data/Mpdf.php');

        $originalClassname = 'WP_Dependency_Installer';

        $classnamePrefix = 'BH_Strauss_Backtrack_Limit_Exhausted_';

        $config = $this->createMock(StraussConfig::class);

        $exception = null;

        $prefixer = new Prefixer($config, $this->getInMemoryFileSystem());

        try {
            $prefixer->replaceClassname($contents, $originalClassname, $classnamePrefix);
        } catch (\Exception $e) {
            $exception = $e;
        }

        self::assertNull($exception);
    }
}
