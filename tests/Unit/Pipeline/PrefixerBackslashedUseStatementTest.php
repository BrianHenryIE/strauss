<?php
/**
 * `use \Bar\Baz;` — an import written with a leading backslash — parses as a plain name, not a fully-qualified one
 * (imports are inherently fully qualified). The replacement logic must treat it exactly like `use Bar\Baz;`, in
 * particular when aliasing renamed global classes.
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Config\PrefixerConfigInterface;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\ChangePlanner
 */
class PrefixerBackslashedUseStatementTest extends TestCase
{
    protected function buildPrefixer(): Prefixer
    {
        $config = $this->createMock(PrefixerConfigInterface::class);
        return new Prefixer($config, $this->getInMemoryFileSystem(), $this->getLogger());
    }

    protected function buildGlobalClassSymbols(File $file): DiscoveredSymbols
    {
        $globalNamespace = new NamespaceSymbol('\\', $file);
        $classSymbol = new ClassSymbol('GlobalThing', $file, $globalNamespace);
        $classSymbol->setDoRename(true);
        $classSymbol->setLocalReplacement('Pfx_GlobalThing');

        return new DiscoveredSymbols([$globalNamespace, $classSymbol]);
    }

    /**
     * @covers ::findGlobalSymbolsPositions
     */
    public function testBackslashedUseOfGlobalClassInNamespaceIsAliased(): void
    {
        $contents = <<<'EOD'
<?php
namespace Foo;
use \GlobalThing;
$a = new GlobalThing();
EOD;

        $file = new File('/a/path.php', 'path.php', '/a/path.php');
        $result = $this->buildPrefixer()->replaceInString($this->buildGlobalClassSymbols($file), $contents, $file);

        self::assertStringContainsString('use Pfx_GlobalThing as GlobalThing;', $result);
    }

    /**
     * @covers ::findGlobalSymbolsPositions
     */
    public function testBackslashedUseOfGlobalClassInGlobalNamespaceIsRenamed(): void
    {
        $contents = <<<'EOD'
<?php
use \GlobalThing;
$a = new GlobalThing();
EOD;

        $file = new File('/a/path.php', 'path.php', '/a/path.php');
        $result = $this->buildPrefixer()->replaceInString($this->buildGlobalClassSymbols($file), $contents, $file);

        self::assertStringContainsString('use Pfx_GlobalThing;', $result);
        self::assertStringContainsString('new Pfx_GlobalThing();', $result);
    }
}
