<?php
/**
 * @see https://github.com/BrianHenryIE/strauss/issues/49
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue49Test extends IntegrationTestCase
{

    /**
     */
    public function test_local_symlinked_repositories_fail()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss-local-symlinked-repositories-fail",
  "minimum-stability": "dev",
  "repositories": {
    "brianhenryie/bh-wp-logger": {
        "type": "path",
        "url": "../bh-wp-logger"
    },
    "brianhenryie/bh-wp-private-uploads": {
        "type": "git",
        "url": "https://github.com/brianhenryie/bh-wp-private-uploads"
    }
  },
  "require": {
    "brianhenryie/bh-wp-logger": "dev-master"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss_Local_Symlinked_Repositories_Fail\\",
      "target_directory": "/strauss/",
      "classmap_prefix": "BH_Strauss_Local_Symlinked_Repositories_Fail_"
    }
  }
}
EOD;

        // 1. Git clone brianhenryie/bh-wp-logger into the temp dir.
        chdir($this->testsWorkingDir);

        exec('git clone https://github.com/BrianHenryIE/bh-wp-logger.git');

        mkdir($this->testsWorkingDir . 'project');

        // 2. Create the project composer.json in a subdir (one level).
        $this->getFileSystem()->write($this->testsWorkingDir . 'project/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir.'project');

        exec('composer install');

        $exitCode = $this->runStrauss($output);

        self::assertEquals(0, $exitCode, $output);
    }
}
