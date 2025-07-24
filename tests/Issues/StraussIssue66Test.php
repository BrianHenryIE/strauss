<?php
/**
 * WPGraphQL had the word "namespace" in a comment and it was tripping up the matches.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/66
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue66Test extends IntegrationTestCase
{

    /**
     */
    public function test_wp_graphql_prefix_main_class()
    {

        $composerJsonString = <<<'EOD'
{
  "require": {
    "wp-graphql/wp-graphql": "^1.12"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "MyProject\\Dependencies\\",
      "classmap_prefix": "Prefix_",
      "constant_prefix": "Prefix_"
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $php_string = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/wp-graphql/wp-graphql/src/WPGraphQL.php');

        self::assertStringContainsString('final class Prefix_WPGraphQL', $php_string);

        $php_string = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/wp-graphql/wp-graphql/src/Registry/Utils/PostObject.php');

        self::assertStringNotContainsString('use MyProject\Dependencies\WPGraphQL;', $php_string);

        self::assertStringContainsString('use Prefix_WPGraphQL as WPGraphQL;', $php_string);
    }
}
