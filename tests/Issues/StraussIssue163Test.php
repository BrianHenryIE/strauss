<?php
/**
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue163Test extends IntegrationTestCase
{
    /**
     * Fatal error: Uncaught Error: Call to undefined function data_get() in test.php:8
     */
    public function test_multiple_autoloaders_breaks_autoloading()
    {
        $composerJsonString = <<<'EOD'
{
  "name": "strauss/issue163",
  "require": {
    "php": ">=7.4",
    "wp-forge/helpers": "2.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\"
    }
  }
}
EOD;

        $phpString = <<<'EOD'
<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/vendor-prefixed/autoload.php';

Company\Project\WP_Forge\Helpers\dataGet( ['akey' => 'success'], 'akey' );
EOD;

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);
        chdir($this->testsWorkingDir);
        exec('composer install --no-dev;');

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        exec('composer dump-autoload --classmap-authoritative');

        file_put_contents($this->testsWorkingDir . '/test.php', $phpString);

        chdir($this->testsWorkingDir);
        exec('php test.php', $output);

        $output = implode(PHP_EOL, $output);

        $this->assertEmpty($output, $output);
    }
}
