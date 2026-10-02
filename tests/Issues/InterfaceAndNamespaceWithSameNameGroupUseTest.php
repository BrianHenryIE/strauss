<?php
/**
 * "multiple symbols with the same name" when a package has an interface and a namespace with the same FQDN,
 * and a file imports from that namespace with a grouped `use` statement.
 *
 * Seen in the wild with opis/json-schema 2.x: interface `Opis\JsonSchema\Variables` and namespace
 * `Opis\JsonSchema\Variables\`, with `use Opis\JsonSchema\Variables\{VariablesContainer};` in
 * `src/Parsers/VariablesTrait.php`.
 *
 * A non-grouped `use Acme\SameName\Widget\WidgetImplementation;` does not trigger the error.
 *
 * @see \BrianHenryIE\Strauss\Pipeline\Prefixer::replaceNamespaces() `if ($symbol = $discoveredSymbols->get($nameStr)) {`
 * @see \BrianHenryIE\Strauss\Types\DiscoveredSymbols::get()
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @coversNothing
 *
 * @see \BrianHenryIE\Strauss\Pipeline\Prefixer (GroupUse handling calls DiscoveredSymbols::get())
 * @see \BrianHenryIE\Strauss\Types\DiscoveredSymbols::get()
 */
class InterfaceAndNamespaceWithSameNameGroupUseTest extends IntegrationTestCase
{
    public function test_group_use_prefix_matching_interface_name_does_not_throw(): void
    {
        $dependencyComposerJsonString = <<<'EOD'
{
  "name": "acme/same-name",
  "autoload": {
    "psr-4": {
      "Acme\\SameName\\": "src/"
    }
  }
}
EOD;

        $interfaceWithSameNameAsNamespacePhpString = <<<'EOD'
<?php
namespace Acme\SameName;

interface Widget {}
EOD;

        $classInNamespaceWithSameNameAsInterfacePhpString = <<<'EOD'
<?php
namespace Acme\SameName\Widget;

use Acme\SameName\Widget;

class WidgetImplementation implements Widget {}
EOD;

        $traitUsingGroupUseStatementPhpString = <<<'EOD'
<?php
namespace Acme\SameName\Consumers;

use Acme\SameName\Widget;
use Acme\SameName\Widget\{WidgetImplementation};

trait WidgetFactoryTrait
{
    protected function createWidget(): Widget
    {
        return new WidgetImplementation();
    }
}
EOD;

        $mainComposerJsonString = <<<'EOD'
{
  "name": "strauss/interface-and-namespace-with-same-name",
  "minimum-stability": "dev",
  "repositories": {
    "acme/same-name": {
      "url": "../dependency",
      "type": "path",
      "options": {
        "symlink": false
      }
    }
  },
  "require": {
    "acme/same-name": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Repro\\Vendor\\",
      "classmap_prefix": "Repro_Vendor_"
    }
  }
}
EOD;

        $dependencyDir = $this->testsWorkingDir . '/dependency';
        mkdir($dependencyDir);
        mkdir($dependencyDir . '/src');
        mkdir($dependencyDir . '/src/Widget');
        mkdir($dependencyDir . '/src/Consumers');
        $this->getFileSystem()->write($dependencyDir . '/composer.json', $dependencyComposerJsonString);
        $this->getFileSystem()->write($dependencyDir . '/src/Widget.php', $interfaceWithSameNameAsNamespacePhpString);
        $this->getFileSystem()->write($dependencyDir . '/src/Widget/WidgetImplementation.php', $classInNamespaceWithSameNameAsInterfacePhpString);
        $this->getFileSystem()->write($dependencyDir . '/src/Consumers/WidgetFactoryTrait.php', $traitUsingGroupUseStatementPhpString);

        mkdir($this->testsWorkingDir . '/project');
        $this->getFileSystem()->write($this->testsWorkingDir . '/project/composer.json', $mainComposerJsonString);
        chdir($this->testsWorkingDir . '/project');
        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $prefixedTraitPhpString = $this->getFileSystem()->read(
            $this->testsWorkingDir . '/project/vendor-prefixed/acme/same-name/src/Consumers/WidgetFactoryTrait.php'
        );

        $this->assertStringContainsString('use Repro\\Vendor\\Acme\\SameName\\Widget;', $prefixedTraitPhpString);
        $this->assertStringContainsString('use Repro\\Vendor\\Acme\\SameName\\Widget\\{WidgetImplementation};', $prefixedTraitPhpString);
    }
}
