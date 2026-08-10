<?php
/**
 * PHP 8.1 enums: discovery, prefixing (namespaced and global), and `class_alias()`-based autoload aliases.
 */

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @coversNothing
 */
class EnumsIntegrationTest extends IntegrationTestCase
{
    /**
     * Installs (via a local path repository, no network) a fixture package containing a backed enum implementing
     * an interface, a pure enum, a global (classmap'd) enum, and a consuming class; runs Strauss; then verifies
     * the prefixed output and the runtime behavior of the generated aliases in a subprocess.
     */
    public function test_enums_are_prefixed_and_aliased(): void
    {
        // Both checks matter: `composer install` and the `php -r` assertions below run with the system PHP.
        $this->markTestSkippedOnPhpVersionBelow('8.1');

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/enums-integration-test",
  "minimum-stability": "dev",
  "repositories": {
    "strauss-test/enum-fixture": {
      "type": "path",
      "url": "./enum-fixture",
      "options": {
        "symlink": false
      }
    }
  },
  "require": {
    "strauss-test/enum-fixture": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\TestEnums\\",
      "classmap_prefix": "BrianHenryIE_TestEnums_",
      "delete_vendor_files": true
    }
  }
}
EOD;

        $fixtureComposerJsonString = <<<'EOD'
{
  "name": "strauss-test/enum-fixture",
  "require": {
    "php": ">=8.1"
  },
  "autoload": {
    "psr-4": {
      "EnumFixture\\": "src/"
    },
    "classmap": [
      "global/"
    ]
  }
}
EOD;

        $hasLabelPhpString = <<<'EOD'
<?php
namespace EnumFixture;

interface HasLabel
{
    public function label(): string;
}
EOD;

        $statusPhpString = <<<'EOD'
<?php
namespace EnumFixture;

enum Status: string implements HasLabel
{
    const DEFAULT = self::Ready;

    case Ready = 'ready';
    case Done = 'done';

    public function label(): string
    {
        return match($this) {
            Status::Ready => 'Ready',
            Status::Done => 'Done',
        };
    }
}
EOD;

        $directionPhpString = <<<'EOD'
<?php
namespace EnumFixture;

enum Direction
{
    case Up;
    case Down;
}
EOD;

        $consumerPhpString = <<<'EOD'
<?php
namespace EnumFixture;

use EnumFixture\Status;

class Consumer
{
    public function report(Status $status): string
    {
        if (!($status instanceof Status)) {
            return '';
        }
        return match($status) {
            Status::Ready => 'consumer:ready',
            Status::Done => 'consumer:done',
        };
    }
}
EOD;

        $globalSuitPhpString = <<<'EOD'
<?php

enum GlobalSuit: int
{
    case Hearts = 1;
    case Spades = 2;
}
EOD;

        $this->getFileSystem()->createDirectory($this->testsWorkingDir . '/enum-fixture');
        $this->getFileSystem()->createDirectory($this->testsWorkingDir . '/enum-fixture/src');
        $this->getFileSystem()->createDirectory($this->testsWorkingDir . '/enum-fixture/global');
        $this->getFileSystem()->write($this->testsWorkingDir . '/enum-fixture/composer.json', $fixtureComposerJsonString);
        $this->getFileSystem()->write($this->testsWorkingDir . '/enum-fixture/src/HasLabel.php', $hasLabelPhpString);
        $this->getFileSystem()->write($this->testsWorkingDir . '/enum-fixture/src/Status.php', $statusPhpString);
        $this->getFileSystem()->write($this->testsWorkingDir . '/enum-fixture/src/Direction.php', $directionPhpString);
        $this->getFileSystem()->write($this->testsWorkingDir . '/enum-fixture/src/Consumer.php', $consumerPhpString);
        $this->getFileSystem()->write($this->testsWorkingDir . '/enum-fixture/global/GlobalSuit.php', $globalSuitPhpString);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install', $composerInstallOutput, $composerInstallExitCode);
        $this->assertEquals(0, $composerInstallExitCode, implode(PHP_EOL, $composerInstallOutput));

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        // The namespaced enums are moved to the prefixed namespace; declarations and bodies are intact.
        $statusPhpString = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/strauss-test/enum-fixture/src/Status.php');
        $this->assertStringContainsString('namespace BrianHenryIE\TestEnums\EnumFixture;', $statusPhpString);
        $this->assertStringContainsString('enum Status: string implements HasLabel', $statusPhpString);
        $this->assertStringContainsString("case Ready = 'ready';", $statusPhpString);

        // The global enum declaration gets the classmap prefix.
        $globalSuitPhpString = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/strauss-test/enum-fixture/global/GlobalSuit.php');
        $this->assertStringContainsString('enum BrianHenryIE_TestEnums_GlobalSuit: int', $globalSuitPhpString);

        // The consuming class's references ride the namespace replacement.
        $consumerPhpString = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/strauss-test/enum-fixture/src/Consumer.php');
        $this->assertStringContainsString('namespace BrianHenryIE\TestEnums\EnumFixture;', $consumerPhpString);
        $this->assertStringContainsString('use BrianHenryIE\TestEnums\EnumFixture\Status;', $consumerPhpString);
        $this->assertStringContainsString('Status::Ready => ', $consumerPhpString);

        // The aliases file contains `class_alias()`-based entries for the enums.
        $aliasesPhpString = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor/composer/autoload_aliases.php');
        $this->assertStringContainsString("'EnumFixture\\\\Status'", $aliasesPhpString);
        $this->assertStringContainsString("'type' => 'enum'", $aliasesPhpString);
        $this->assertStringContainsString("'concrete' => 'BrianHenryIE\\\\TestEnums\\\\EnumFixture\\\\Status'", $aliasesPhpString);
        $this->assertStringContainsString("'GlobalSuit'", $aliasesPhpString);
        $this->assertStringContainsString("case 'enum':", $aliasesPhpString);
        $this->assertStringContainsString('class_alias', $aliasesPhpString);

        exec('composer dump-autoload');

        // Case identity, ::from(), instanceof, and match must all work through the aliases at runtime.
        // The last three entries pin a known limitation: unlike the `extends` shim used for classes, a
        // `class_alias()`'d enum does not implement its original interface names, so `instanceof` against the
        // aliased original interface is false — only the renamed interface matches. (`interface_exists()` is
        // needed first because `instanceof` does not trigger autoloading of the alias.)
        $testPhpString = implode('', [
            "require_once 'vendor-prefixed/autoload.php';",
            "require_once 'vendor/composer/autoload_aliases.php';",
            "require_once 'vendor/autoload.php';",
            'echo json_encode([',
            '\EnumFixture\Status::Ready === \BrianHenryIE\TestEnums\EnumFixture\Status::Ready,',
            '\EnumFixture\Status::from(\'done\')->label(),',
            '\EnumFixture\Direction::Up === \BrianHenryIE\TestEnums\EnumFixture\Direction::Up,',
            '\GlobalSuit::Hearts instanceof \BrianHenryIE_TestEnums_GlobalSuit,',
            '(new \BrianHenryIE\TestEnums\EnumFixture\Consumer())->report(\EnumFixture\Status::Done),',
            'interface_exists(\'EnumFixture\HasLabel\'),',
            '\EnumFixture\Status::Ready instanceof \EnumFixture\HasLabel,',
            '\EnumFixture\Status::Ready instanceof \BrianHenryIE\TestEnums\EnumFixture\HasLabel,',
            ']);',
        ]);

        exec(sprintf('php -r %s', escapeshellarg($testPhpString)), $runtimeOutput, $runtimeExitCode);
        $runtimeOutput = implode(PHP_EOL, $runtimeOutput);

        $this->assertEquals(0, $runtimeExitCode, $runtimeOutput);
        $this->assertEquals('[true,"Done",true,true,"consumer:done",true,false,true]', $runtimeOutput);
    }
}
