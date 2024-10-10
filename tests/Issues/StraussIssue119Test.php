<?php
/**
 * `class final` appears in `symfony/console/CHANGELOG.md` causing `symfony/polyfill-php80/Resources/stubs/Attribute.php`
 * `final` keyword to be prefixed
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/119
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue119Test extends IntegrationTestCase
{
    public function test_muted_errors()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "issue/119",
  "require": {
    "symfony/polyfill-php80": "*",
    "symfony/console": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\",
      "classmap_prefix": "Company_Project_"
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $this->runStrauss();

        $php_string = file_get_contents($this->testsWorkingDir . '/vendor-prefixed/symfony/polyfill-php80/Resources/stubs/Attribute.php');

        self::assertStringNotContainsString('Company_Project_final class Attribute', $php_string);
    }
}
