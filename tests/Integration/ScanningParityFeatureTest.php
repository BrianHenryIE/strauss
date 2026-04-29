<?php

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\IntegrationTestCase;
use BrianHenryIE\Strauss\Tests\PhpAstAssertions;

/**
 * @coversNothing
 */
class ScanningParityFeatureTest extends IntegrationTestCase
{
    use PhpAstAssertions;

    public function test_scanning_changes_do_not_change_transformed_output(): void
    {
        $dependencyComposerJson = <<<'JSON'
{
  "name": "local/scan-fixture",
  "version": "1.0.0",
  "autoload": {
    "psr-4": {
      "LocalScan\\": "src/"
    },
    "files": [
      "src/globals.php",
      "src/multi.php"
    ]
  }
}
JSON;

        $namedPhp = <<<'PHP'
<?php
namespace LocalScan;

class NamedClass {}
PHP;

        $globalsPhp = <<<'PHP'
<?php
namespace {
    class GlobalClass {}

    function global_helper() {
        return 'ok';
    }

    const GLOBAL_CONST = 'global';
}
PHP;

        $consumerPhp = <<<'PHP'
<?php
namespace LocalScan;

class Consumer {
    public function run(): array {
        $class = new \GlobalClass();
        $value = \global_helper();
        $const = \GLOBAL_CONST;
        return [$class::class, $value, $const];
    }
}
PHP;

        $multiPhp = <<<'PHP'
<?php
namespace LocalScan\PartA {
    class Alpha {}
}

namespace {
    function shared_helper() {
        return true;
    }
}

namespace LocalScan\PartB {
    class Beta {}
}
PHP;

        $projectComposerJson = <<<'JSON'
{
  "name": "local/scanning-parity-project",
  "version": "1.0.0",
  "minimum-stability": "dev",
  "prefer-stable": true,
  "repositories": [
    {
      "type": "path",
      "url": "../scan-fixture",
      "options": {
        "symlink": false
      }
    }
  ],
  "require": {
    "local/scan-fixture": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "MyPrefix\\",
      "classmap_prefix": "MyPrefix_",
      "functions_prefix": "myprefix_",
      "constants_prefix": "MYPREFIX_",
      "target_directory": "vendor-prefixed",
      "classmap_output": false
    }
  }
}
JSON;

        mkdir($this->testsWorkingDir . '/scan-fixture/src', 0777, true);
        $this->getFileSystem()->write($this->testsWorkingDir . '/scan-fixture/composer.json', $dependencyComposerJson);
        $this->getFileSystem()->write($this->testsWorkingDir . '/scan-fixture/src/NamedClass.php', $namedPhp);
        $this->getFileSystem()->write($this->testsWorkingDir . '/scan-fixture/src/globals.php', $globalsPhp);
        $this->getFileSystem()->write($this->testsWorkingDir . '/scan-fixture/src/Consumer.php', $consumerPhp);
        $this->getFileSystem()->write($this->testsWorkingDir . '/scan-fixture/src/multi.php', $multiPhp);

        mkdir($this->testsWorkingDir . '/project', 0777, true);
        $this->getFileSystem()->write($this->testsWorkingDir . '/project/composer.json', $projectComposerJson);

        chdir($this->testsWorkingDir . '/project');
        exec('composer install --no-interaction --no-progress --no-ansi --quiet', $composerInstallOutput, $composerInstallExitCode);
        $this->assertEquals(0, $composerInstallExitCode, implode(PHP_EOL, $composerInstallOutput));

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $namedPath = $this->testsWorkingDir . '/project/vendor-prefixed/local/scan-fixture/src/NamedClass.php';
        $globalsPath = $this->testsWorkingDir . '/project/vendor-prefixed/local/scan-fixture/src/globals.php';
        $consumerPath = $this->testsWorkingDir . '/project/vendor-prefixed/local/scan-fixture/src/Consumer.php';
        $multiPath = $this->testsWorkingDir . '/project/vendor-prefixed/local/scan-fixture/src/multi.php';

        $this->assertTrue($this->getFileSystem()->fileExists($namedPath));
        $this->assertTrue($this->getFileSystem()->fileExists($globalsPath));
        $this->assertTrue($this->getFileSystem()->fileExists($consumerPath));
        $this->assertTrue($this->getFileSystem()->fileExists($multiPath));

        $namedOutput = $this->getFileSystem()->read($namedPath);
        $globalsOutput = $this->getFileSystem()->read($globalsPath);
        $consumerOutput = $this->getFileSystem()->read($consumerPath);
        $multiOutput = $this->getFileSystem()->read($multiPath);

        $this->assertSame(['MyPrefix\\LocalScan'], $this->getNamespaces($namedOutput));
        $this->assertContains('NamedClass', $this->getClassNames($namedOutput));

        $this->assertContains('MyPrefix_GlobalClass', $this->getClassNames($globalsOutput));
        $this->assertContains('myprefix_global_helper', $this->getFunctionDeclarationNames($globalsOutput));
        $this->assertContains('MYPREFIX_GLOBAL_CONST', $this->getConstantDeclarationNames($globalsOutput));

        $this->assertSame(['MyPrefix\\LocalScan'], $this->getNamespaces($consumerOutput));
        $this->assertContains('MyPrefix_GlobalClass', $this->getNewClassNames($consumerOutput));
        $this->assertContains('myprefix_global_helper', $this->getFunctionCallNames($consumerOutput));
        $this->assertContains('MYPREFIX_GLOBAL_CONST', $this->getConstFetchNames($consumerOutput));

        $this->assertContains('MyPrefix\\LocalScan\\PartA', $this->getNamespaces($multiOutput));
        $this->assertContains('MyPrefix\\LocalScan\\PartB', $this->getNamespaces($multiOutput));
        $this->assertContains('myprefix_shared_helper', $this->getFunctionDeclarationNames($multiOutput));
    }
}
