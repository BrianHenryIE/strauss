<?php
/**
 * Problem with too many replacements due to common class, domain, namespace names, "Normalizer".
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/27
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue27Test extends IntegrationTestCase
{

    /**
     */
    public function test_virtual_package()
    {
        /**
         * @see https://github.com/BrianHenryIE/strauss/commit/1bd20b75a4e6b5c07a428c04e8b9e514034b6b5c
         */
        self::markTestSkipped('Polyfills are no longer prefixed.');

        $composerJsonString = <<<'EOD'
{
  "require": {
    "symfony/polyfill-intl-normalizer": "1.23"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Normalizer_Test\\",
      "classmap_prefix": "Normalizer_Test_"
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $php_string = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/symfony/polyfill-intl-normalizer/Normalizer.php');

        self::assertStringNotContainsString('namespace Normalizer_Test\Symfony\Polyfill\Intl\Normalizer_Test_Normalizer;', $php_string);
        self::assertStringContainsString('namespace Normalizer_Test\Symfony\Polyfill\Intl\Normalizer;', $php_string);

        self::assertStringNotContainsString('class Normalizer_Test_Normalizer', $php_string);
        self::assertStringContainsString('class Normalizer', $php_string);


        $php_string = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/symfony/polyfill-intl-normalizer/Resources/stubs/Normalizer.php');

        self::assertStringNotContainsString('class Normalizer_Test_Normalizer extends Normalizer_Test\Symfony\Polyfill\Intl\Normalizer_Test_Normalizer\Normalizer', $php_string);
        self::assertStringContainsString('class Normalizer_Test_Normalizer extends Normalizer_Test\Symfony\Polyfill\Intl\Normalizer\Normalizer', $php_string);
    }
}
