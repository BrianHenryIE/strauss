<?php
/**
 * Symbols of different types may share a fully qualified name: an interface `Acme\Widget` and a namespace
 * `Acme\Widget\`, a global class `WPGraphQL` and a namespace `WPGraphQL\`, a function and a namespace, ...
 *
 * Looking such a name up with {@see DiscoveredSymbols::get()} throws "multiple symbols with the same name", and
 * merging collections with {@see DiscoveredSymbols::toArray()} silently drops one of them. The planner must look
 * symbols up by the type the syntax implies.
 *
 * @see \BrianHenryIE\Strauss\Tests\Issues\InterfaceAndNamespaceWithSameNameGroupUseTest
 * @see \BrianHenryIE\Strauss\Tests\Issues\StraussIssue66Test
 */

namespace BrianHenryIE\Strauss\Tests\Unit\Pipeline;

use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Pipeline\ChangePlanner;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\PhpFileAnalyzer;
use BrianHenryIE\Strauss\Pipeline\Prefixer;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Config\PrefixerConfigInterface;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\InterfaceSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\ChangePlanner
 */
class ChangePlannerSameNameSymbolsTest extends TestCase
{
    protected function getFile(string $path = 'vendor/acme/package/src/file.php'): File
    {
        return new File($path, substr($path, strlen('vendor/')), 'vendor-prefixed/' . substr($path, strlen('vendor/')));
    }

    protected function getNamespace(string $name, string $replacement): NamespaceSymbol
    {
        $namespace = new NamespaceSymbol($name, $this->getFile());
        $namespace->setDoRename(true);
        $namespace->setLocalReplacement($replacement);
        return $namespace;
    }

    /**
     * Plan the contents and apply the plan, so the tests assert on the resulting code.
     */
    protected function prefix(string $contents, DiscoveredSymbols $symbols): string
    {
        $file = $this->getFile();
        $file->setCodeMap((new PhpFileAnalyzer())->analyze($contents));
        (new ChangePlanner())->planInFiles($symbols, [$file]);

        $config = $this->createMock(PrefixerConfigInterface::class);
        return (new Prefixer($config, $this->getInMemoryFileSystem()))->replaceInString($symbols, $contents, $file);
    }

    /**
     * Interface `Acme\SameName\Widget` and namespace `Acme\SameName\Widget`: the prefix of a grouped `use` is the
     * namespace. The non-grouped `use` of the interface and the type hint are also renamed.
     *
     * @covers ::findNamespacePositions
     */
    public function test_interface_and_namespace_with_same_name_group_use(): void
    {
        $root = $this->getNamespace('Acme\SameName', 'Prefix\Acme\SameName');
        $widgetNamespace = $this->getNamespace('Acme\SameName\Widget', 'Prefix\Acme\SameName\Widget');
        $interface = new InterfaceSymbol('Acme\SameName\Widget', $this->getFile(), $root);
        $interface->setDoRename(true);
        $implementation = new ClassSymbol('Acme\SameName\Widget\WidgetImplementation', $this->getFile(), $widgetNamespace);
        $implementation->setDoRename(true);

        $symbols = new DiscoveredSymbols([$root, $widgetNamespace, $interface, $implementation]);

        $contents = <<<'EOD'
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

        $expected = <<<'EOD'
<?php
namespace Prefix\Acme\SameName\Consumers;

use Prefix\Acme\SameName\Widget;
use Prefix\Acme\SameName\Widget\{WidgetImplementation};

trait WidgetFactoryTrait
{
    protected function createWidget(): Widget
    {
        return new WidgetImplementation();
    }
}
EOD;

        self::assertSame($expected, $this->prefix($contents, $symbols));
    }

    /**
     * The grouped `use` prefix is renamed via the namespace even when only a parent namespace is marked for
     * renaming (the `Widget` sub-namespace is not itself a symbol to rename).
     *
     * @covers ::findNamespacePositions
     */
    public function test_group_use_prefix_matching_interface_name_with_parent_namespace_renamed(): void
    {
        $root = $this->getNamespace('Acme\SameName', 'Prefix\Acme\SameName');
        $interface = new InterfaceSymbol('Acme\SameName\Widget', $this->getFile(), $root);
        $interface->setDoRename(true);

        $symbols = new DiscoveredSymbols([$root, $interface]);

        $contents = "<?php\nuse Acme\\SameName\\Widget\\{WidgetImplementation, Other};\n";

        self::assertSame(
            "<?php\nuse Prefix\\Acme\\SameName\\Widget\\{WidgetImplementation, Other};\n",
            $this->prefix($contents, $symbols)
        );
    }

    /**
     * Global class `WPGraphQL` and namespace `WPGraphQL`: the class declaration gets the classmap prefix and the
     * namespace imports get the namespace prefix.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/66
     *
     * @covers ::findGlobalSymbolsPositions
     * @covers ::findNamespacePositions
     */
    public function test_global_class_and_namespace_with_same_name(): void
    {
        $global = new NamespaceSymbol('\\');
        $namespace = $this->getNamespace('WPGraphQL', 'Prefix\WPGraphQL');
        $class = new ClassSymbol('WPGraphQL', $this->getFile('vendor/wp-graphql/wp-graphql/src/WPGraphQL.php'), $global);
        $class->setDoRename(true);
        $class->setLocalReplacement('Prefix_WPGraphQL');
        $router = new ClassSymbol('WPGraphQL\Router', $this->getFile('vendor/wp-graphql/wp-graphql/src/Router.php'), $namespace);
        $router->setDoRename(true);

        $symbols = new DiscoveredSymbols([$global, $namespace, $class, $router]);

        $contents = <<<'EOD'
<?php

use WPGraphQL\Router;

final class WPGraphQL {
    public static function router(): Router {
        return new \WPGraphQL\Router();
    }
}

$instance = new WPGraphQL();
EOD;

        $expected = <<<'EOD'
<?php

use Prefix\WPGraphQL\Router;

final class Prefix_WPGraphQL {
    public static function router(): Router {
        return new \Prefix\WPGraphQL\Router();
    }
}

$instance = new Prefix_WPGraphQL();
EOD;

        self::assertSame($expected, $this->prefix($contents, $symbols));
    }

    /**
     * Function `Acme\Util\helper` and namespace `Acme\Util\helper`: `use function` looks up a function.
     *
     * @covers ::findNamespacePositions
     */
    public function test_function_and_namespace_with_same_name(): void
    {
        $root = $this->getNamespace('Acme\Util', 'Prefix\Acme\Util');
        $helperNamespace = $this->getNamespace('Acme\Util\helper', 'Prefix\Acme\Util\helper');
        $function = new FunctionSymbol('Acme\Util\helper', $this->getFile(), $root);
        $function->setDoRename(true);
        $function->setLocalReplacement('Prefix\Acme\Util\helper');

        $symbols = new DiscoveredSymbols([$root, $helperNamespace, $function]);

        $contents = <<<'EOD'
<?php
namespace Acme\Consumer;

use function Acme\Util\helper;
use Acme\Util\helper\{Thing};

helper();
EOD;

        $expected = <<<'EOD'
<?php
namespace Acme\Consumer;

use function Prefix\Acme\Util\helper;
use Prefix\Acme\Util\helper\{Thing};

helper();
EOD;

        self::assertSame($expected, $this->prefix($contents, $symbols));
    }

    /**
     * Constant `Acme\Util\VERSION` and namespace `Acme\Util\VERSION`: `use const` looks up a constant.
     *
     * @covers ::findNamespacePositions
     */
    public function test_constant_and_namespace_with_same_name(): void
    {
        $root = $this->getNamespace('Acme\Util', 'Prefix\Acme\Util');
        $versionNamespace = $this->getNamespace('Acme\Util\VERSION', 'Prefix\Acme\Util\VERSION');
        $constant = new ConstantSymbol('Acme\Util\VERSION', $this->getFile(), $root);
        $constant->setDoRename(true);
        $constant->setLocalReplacement('Prefix\Acme\Util\VERSION');

        $symbols = new DiscoveredSymbols([$root, $versionNamespace, $constant]);

        $contents = "<?php\nnamespace Acme\\Consumer;\n\nuse const Acme\\Util\\VERSION;\nuse Acme\\Util\\VERSION\\{Thing};\n";

        self::assertSame(
            "<?php\nnamespace Acme\\Consumer;\n\nuse const Prefix\\Acme\\Util\\VERSION;\nuse Prefix\\Acme\\Util\\VERSION\\{Thing};\n",
            $this->prefix($contents, $symbols)
        );
    }

    /**
     * A global class and a global function with the same name: a fully qualified reference to the class is looked
     * up among classes, not among every namespaced symbol.
     *
     * @covers ::findGlobalSymbolsPositions
     */
    public function test_global_class_and_function_with_same_name(): void
    {
        $global = new NamespaceSymbol('\\');
        $class = new ClassSymbol('Widget', $this->getFile(), $global);
        $class->setDoRename(true);
        $class->setLocalReplacement('Prefix_Widget');
        $function = new FunctionSymbol('Widget', $this->getFile(), $global);
        $function->setDoRename(true);
        $function->setLocalReplacement('prefix_Widget');

        $symbols = new DiscoveredSymbols([$global, $class, $function]);

        $contents = "<?php\nnamespace Acme;\n\n\$w = new \\Widget();\n";

        self::assertSame(
            "<?php\nnamespace Acme;\n\n\$w = new \\Prefix_Widget();\n",
            $this->prefix($contents, $symbols)
        );
    }

    /**
     * The non-grouped form never triggered the error, and must keep working.
     *
     * @covers ::findNamespacePositions
     */
    public function test_interface_and_namespace_with_same_name_plain_use(): void
    {
        $root = $this->getNamespace('Acme\SameName', 'Prefix\Acme\SameName');
        $widgetNamespace = $this->getNamespace('Acme\SameName\Widget', 'Prefix\Acme\SameName\Widget');
        $interface = new InterfaceSymbol('Acme\SameName\Widget', $this->getFile(), $root);
        $interface->setDoRename(true);
        $implementation = new ClassSymbol('Acme\SameName\Widget\WidgetImplementation', $this->getFile(), $widgetNamespace);
        $implementation->setDoRename(true);

        $symbols = new DiscoveredSymbols([$root, $widgetNamespace, $interface, $implementation]);

        $contents = "<?php\nnamespace Acme\\SameName\\Widget;\n\nuse Acme\\SameName\\Widget;\nuse Acme\\SameName\\Widget\\WidgetImplementation;\n\nclass Other extends WidgetImplementation implements Widget {}\n";

        self::assertSame(
            "<?php\nnamespace Prefix\\Acme\\SameName\\Widget;\n\nuse Prefix\\Acme\\SameName\\Widget;\nuse Prefix\\Acme\\SameName\\Widget\\WidgetImplementation;\n\nclass Other extends WidgetImplementation implements Widget {}\n",
            $this->prefix($contents, $symbols)
        );
    }
}
