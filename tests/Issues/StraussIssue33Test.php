<?php
/**
 *
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Config\PrefixerConfigInterface;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Pipeline\Prefixer;
use BrianHenryIE\Strauss\IntegrationTestCase;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use Mockery;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue33Test extends IntegrationTestCase
{

    /**
     */
    public function test_backtrack_limit_exhausted(): void
    {
        if (version_compare(phpversion(), '8.1', '>=')) {
            $this->markTestSkipped("Package specified for test is not PHP 8.1 compatible. Running tests under PHP " . phpversion());
        }

        $this->markTestSkipped('passes when run alone.');

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

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);

        $this->assertEquals(0, $exitCode, $output);
    }



    /**
     *
     */
    public function test_unit_backtrack_limit_exhausted(): void
    {
        $contents = $this->getFileSystem()->read(__DIR__.'/data/Mpdf.php');

        $originalClassname = 'WP_Dependency_Installer';

        $classnamePrefix = 'BH_Strauss_Backtrack_Limit_Exhausted_';

        $config = Mockery::mock(PrefixerConfigInterface::class);
        $config->shouldReceive('getClassmapPrefix')->andReturn('Prefixer_Test_');
        $config->shouldReceive('getNamespacePrefix')->andReturn('Prefixer\\Test\\');
        $config->shouldReceive('getConstantsPrefix')->andReturn('Prefixer_Test_');

        $exception = null;

        $prefixer = new Prefixer($config, $this->getInMemoryFileSystem());

        $file = new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );

        $classSymbol = new ClassSymbol($originalClassname, $file);
        $classSymbol->setLocalReplacement($classnamePrefix . $originalClassname);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($classSymbol);

        try {
            $prefixer->replaceInString($discoveredSymbols, $contents, $file);
        } catch (\Exception $e) {
            $exception = $e;
        }

        self::assertNull($exception);
    }
}
