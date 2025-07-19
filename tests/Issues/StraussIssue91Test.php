<?php
/**
 * Undefined offset: 1
 *
 * @see https://github.com/BrianHenryIE/strauss/pull/91
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue91Test extends IntegrationTestCase
{
    public function test_issue_91()
    {
        $composerJsonString = <<<'EOD'
{
  "name": "pr/91",
  "require": {
    "phpoffice/phpspreadsheet": "1.29"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\"
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output);

        $this->assertEquals(0, $exitCode, $output);
    }
}
