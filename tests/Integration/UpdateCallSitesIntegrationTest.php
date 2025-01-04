<?php

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @coversNothing
 */
class UpdateCallSitesIntegrationTest extends IntegrationTestCase
{

    /**
     * @see https://github.com/twigphp/Twig/tree/v2.16.1
     * @see https://github.com/twigphp/Twig/blob/v2.16.1/src/Extension/CoreExtension.php
     */
    public function test_updateCallSites_functions(): void
    {

        $file1 = <<<'EOD'
<?php
// strausstest

$v = twig_cycle([1,2,3], 1);
EOD;

        $file2 = <<<'EOD'
<?php
// strausstest

namespace Strauss\Tests;

use function twig_cycle as my_twig_cycle;

$v = my_twig_cycle([1,2,3], 1);
EOD;

        $composerJsonString = <<<'EOD'
{
  "require": {
    "twig/twig": "v2.16.1"
  },
  "autoload": {
    "files": ["file1.php", "file2.php"]
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BH_Strauss_",
      "target_directory": "vendor",
      "update_call_sites": true
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);
        file_put_contents($this->testsWorkingDir . 'file1.php', $file1);
        file_put_contents($this->testsWorkingDir . 'file2.php', $file2);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $this->runStrauss();
        $this->runStrauss();

        $phpString = file_get_contents($this->testsWorkingDir .'vendor/twig/twig/src/Extension/CoreExtension.php');
        $this->assertStringNotContainsString('function twig_cycle(', $phpString);
        $this->assertStringContainsString('function bh_strauss_twig_cycle(', $phpString);

        $project_file_php_string = file_get_contents($this->testsWorkingDir . 'file1.php');
        $this->assertStringNotContainsString('$v = twig_cycle(', $project_file_php_string);
        $this->assertStringContainsString('$v = bh_strauss_twig_cycle(', $project_file_php_string);

        $project_file_php_string = file_get_contents($this->testsWorkingDir . 'file2.php');
        $this->assertStringNotContainsString('use function twig_cycle as my_twig_cycle;', $project_file_php_string);
        $this->assertStringContainsString('use function bh_strauss_twig_cycle as my_twig_cycle;', $project_file_php_string);
    }
}
