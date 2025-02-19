<?php

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @coversNothing
 */
class AliasesFeatureTest extends IntegrationTestCase
{

    /**
     * Fatal error: Uncaught Error: Class "Psr\Log\Test\TestLogger" not found in /...project/vendor/brianhenryie/color-logger/src/ColorLogger.php on line 14
     *
     * Error: Class "Psr\Log\Test\TestLogger" not found in /...project/vendor/brianhenryie/color-logger/src/ColorLogger.php on line 14
     *
     * Call Stack:
     * 0.0000     516896   1. {main}() Command line code:0
     * 0.0013     703968   2. Composer\Autoload\ClassLoader->loadClass($class = 'BrianHenryIE\\ColorLogger\\ColorLogger') Command line code:1
     * 0.0013     704160   3. Composer\Autoload\{closure:/...project/vendor/composer/ClassLoader.php:575-577}($file = '/...project/vendor/composer/../brianhenryie/color-logger/src/ColorLogger.php') /...project/vendor/composer/ClassLoader.php:427
     * 0.0015     711312   4. include('/...project/vendor/brianhenryie/color-logger/src/ColorLogger.php') /...project/vendor/composer/ClassLoader.php:576
     */
    public function test_happy_path(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/aliasfeaturetest",
  "require": {
    "psr/log": "*"
  },
  "require-dev": {
    "brianhenryie/color-logger": "*",
    "phpunit/phpunit": "*"
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

        $autoloadRealPhpString = file_get_contents($this->testsWorkingDir . 'vendor/autoload.php');

        $this->assertStringContainsString('autoload_aliases.php', $autoloadRealPhpString);

        /**
         * `php -r "require_once 'vendor/autoload.php'; new \BrianHenryIE\ColorLogger\ColorLogger();"`
         * `cat vendor/composer/autoload_aliases.php`
         */
        exec('php -r "require_once \'vendor/autoload.php\'; new \BrianHenryIE\ColorLogger\ColorLogger();"', $output);
        $output = implode(PHP_EOL, $output);

        $this->assertEmpty($output, $output);
    }
}
