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
    public function test_local_symlinked_repositories_fail(): void
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss-local-symlinked-repositories-fail",
  "minimum-stability": "dev",
  "repositories": {
    "brianhenryie/symlinked": {
        "type": "path",
        "url": "../symlinked"
    }
  },
  "require": {
    "brianhenryie/symlinked": "*"
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

        $symlinkedComposerJsonString = <<<'EOD'
{
  "name": "brianhenryie/symlinked",
  "autoload": {
    "classmap": [
      "src"
    ]
  }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->createDirectory($this->testsWorkingDir . '/symlinked');
        $this->getFileSystem()->write($this->testsWorkingDir . '/symlinked/composer.json', $symlinkedComposerJsonString);
        $this->getFileSystem()->createDirectory($this->testsWorkingDir . '/symlinked/src');
        $this->getFileSystem()->write($this->testsWorkingDir . '/symlinked/src/file.php', '<?php ');

        // 2. Create the project composer.json in a subdir (one level).
        $this->getFileSystem()->createDirectory($this->testsWorkingDir . '/project');
        $this->getFileSystem()->write($this->testsWorkingDir . '/project/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir.'/project');

        exec('composer install', $composerInstallOutput, $composerInstallExitCode);
        $this->assertEquals(0, $composerInstallExitCode, implode(PHP_EOL, $composerInstallOutput));

        $exitCode = $this->runStrauss($output);

        $this->assertEquals(0, $exitCode, $output);
    }
}
