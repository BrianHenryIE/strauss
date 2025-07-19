<?php
/**
 * A PSR-0 test.
 *
 * This worked very easily because once the files are copied, Strauss doesn't care about autoloaders, just if you
 * are a class in a global namespace or if its a namespace that should br prefixed.
 *
 * @see https://github.com/coenjacobs/mozart/issues/99
 *
 * @see https://github.com/sdrobov/autopsr4
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * Class MozartIssue99Test
 * @coversNothing
 */
class MozartIssue99Test extends IntegrationTestCase
{

    /**
     *
     */
    public function test_mustache()
    {

        $composerJsonString = <<<'EOD'
{
  "require": {
    "mustache/mustache": "2.13.0"
  },
  "extra": {
    "strauss": {
      "target_directory": "strauss",
      "namespace_prefix": "Strauss\\",
      "classmap_prefix": "Strauss_"
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $this->markTestIncomplete("What to assert!?");
    }
}
