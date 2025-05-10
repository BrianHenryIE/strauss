<?php
/**
 * New `bootstrap.php` file to load the aliases file is incorrect
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/183
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue183Test extends IntegrationTestCase
{

    public static function bootstrapDataProvider(): array
    {
        return [
            [''],
            ['      "target_directory": "custom-vendor-prefixed",'],
        ];
    }

    /**
     * @dataProvider \BrianHenryIE\Strauss\Tests\Issues\StraussIssue183Test::bootstrapDataProvider
     */
    public function test_bootstrap(string $targetDirectoryJsonLine)
    {
        $straussAbsoluteDir = getcwd();
        $composerJsonString = <<<EOD
{
  "name": "strauss/issue183",
  "require": {
    "psr/log": "*"
  },
  "require-dev": {
    "brianhenryie/strauss": "dev-master"
  },
  "extra": {
    "strauss": {
$targetDirectoryJsonLine
      "namespace_prefix": "Strauss\\\\Issue183\\\\",
      "delete_vendor_packages": true
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        file_put_contents(
            $this->testsWorkingDir . '/vendor/brianhenryie/strauss/bootstrap.php',
            file_get_contents($straussAbsoluteDir . '/bootstrap.php')
        );

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        // `2>&1` redirect stderr to stdout
        exec('composer dump-autoload 2>&1', $output, $result_code);

        $outputString = implode(PHP_EOL, $output);
        $this->assertEquals(0, $result_code, $outputString);

        // php -r "include __DIR__ . '/vendor/autoload.php'; new \Psr\Log\NullLogger();"
        exec('php -r "include __DIR__ . \'/vendor/autoload.php\'; new \Psr\Log\NullLogger();" 2>&1', $output, $result_code);
        $outputString = implode(PHP_EOL, $output);

        $this->assertEquals(0, $result_code, $outputString);
    }
}
