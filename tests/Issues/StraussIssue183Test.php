<?php
/**
 * New `bootstrap.php` file to load the aliases file is incorrect
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/183
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

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
        $straussAbsoluteDir = dirname(__DIR__, 2);
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

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        file_put_contents(
            $this->testsWorkingDir . '/vendor/brianhenryie/strauss/bootstrap.php',
            $this->getFileSystem()->read($straussAbsoluteDir . '/bootstrap.php')
        );

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        // `2>&1` redirect stderr to stdout
        exec('composer dump-autoload 2>&1', $output, $result_code);

        $outputString = implode(PHP_EOL, $output);
        $this->assertEquals(0, $result_code, $outputString);

        // php -r "include __DIR__ . '/vendor/autoload.php'; new \Psr\Log\NullLogger();"
        exec('php -r "include __DIR__ . \'/vendor/autoload.php\'; new \Strauss\Issue183\Psr\Log\NullLogger();" 2>&1', $output, $result_code);
        $outputString = implode(PHP_EOL, $output);

        $this->assertEquals(0, $result_code, $outputString);
    }

    /**
     * Test that e.g. `use \Psr\Log\LoggerAwareTrait` still works via the alias file after deleting the original file.
     *
     * Mentions of `allow_url_include` etc in the test were when trying to `include` streamwrapped files to load the
     * new PHP, but this is not possible.
     */
    public function test_allow_url_include(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "strauss/issue183",
  "require": {
    "psr/log": "1.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Strauss\\Issue183\\",
      "delete_vendor_packages": true
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        // vendor/composer/autoload_static.php
        $autoloadStaticPhpString = file_get_contents($this->testsWorkingDir .'/vendor-prefixed/composer/autoload_static.php');
        $this->assertStringContainsString('public static $classMap =', $autoloadStaticPhpString, 'Classmap not in autoload_static.');
        $this->assertStringContainsString('Strauss\\\\Issue183\\\\Psr\\\\Log\\\\LoggerAwareTrait', $autoloadStaticPhpString, 'Classmap not in autoload_static.');

        // Directive 'allow_url_include' is deprecated

        // php -r "print_r(ini_get_all()['allow_url_include']);"
        // php -d allow_url_include=on -r "print_r(ini_get_all()['allow_url_include']);"
        // php -d allow_url_include=off -r "print_r(ini_get_all()['allow_url_include']);"

        // php -r "include __DIR__ . '/vendor/autoload.php'; new class() { use \Psr\Log\LoggerAwareTrait; };"

        // Get the loaded PHP ini file
        // php --ini | grep Loaded | grep -o '\S*$'
        // PHP_INI_FILE=$(php --ini | grep Loaded | grep -o '\S*$')
        // cat $PHP_INI_FILE | grep allow_url_include

        // macOS
        // sed -i '' 's/allow_url_include = Off/allow_url_include = On/g' $PHP_INI_FILE
        // sed -i '' 's/allow_url_include = On/allow_url_include = Off/g' $PHP_INI_FILE

        // Deprecated: Directive 'allow_url_include' is deprecated in Unknown on line 0

        // https://www.php.net/manual/en/filesystem.configuration.php#ini.allow-url-include

        // php -d allow_url_include=on -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/strauss

        // php -r "include __DIR__ . '/vendor-prefixed/autoload.php'; new class() { use Strauss\Issue183\Psr\Log\LoggerAwareTrait; };"
        exec('php -r "include __DIR__ . \'/vendor-prefixed/autoload.php\'; new class() { use Strauss\Issue183\Psr\Log\LoggerAwareTrait; };" 2>&1', $output, $result_code);
        $outputString = implode(PHP_EOL, $output);
        $this->assertEquals(0, $result_code, $outputString);

        exec('composer dump-autoload');

        $output = null;
        $exitCode = $this->runStrauss($output, 'include-autoloader');
        $this->assertEquals(0, $exitCode, $output);

        exec('php -r "include __DIR__ . \'/vendor/autoload.php\'; new class() { use \Psr\Log\LoggerAwareTrait; };" 2>&1', $output, $result_code);
        $outputString = implode(PHP_EOL, $output);
        $this->assertEquals(0, $result_code, $outputString);
    }
}
