<?php
/**
 * After scanning, each File carries a code map: the symbols it defines and every symbol it uses, by type, from a
 * single parse. This is the data the rest of the pipeline works from.
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Files\CodeLocation;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\SymbolDefinition;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\FileSymbolScanner
 */
class FileSymbolScannerCodeMapTest extends TestCase
{
    /**
     * @covers ::findInFiles
     */
    public function testFileCodeMapListsDefinitionsAndUsagesByType(): void
    {
        $contents = <<<'EOD'
<?php

namespace Acme\Widgets;

use Acme\Base\Model;
use function Acme\Helpers\format;
use const Acme\Helpers\VERSION;

interface HasName {}
trait Named {}
enum Kind: string { case A = 'a'; }
const DEFAULT_KIND = 'a';
define('ACME_LOADED', true);

class Widget extends Model implements HasName, \Countable
{
    use Named;

    public function count(): int
    {
        return format(VERSION) + \strlen(Kind::A->value);
    }
}

function make(): Widget
{
    return new Widget();
}
EOD;

        $filesystem = Mockery::mock(FileSystem::class);
        $filesystem->expects('read')->once()->andReturn($contents);
        $filesystem->expects('getRelativePath')->once()->andReturnArg(1);

        $config = $this->createMock(StraussConfig::class);
        $config->method('getPackagesToPrefix')->willReturn([]);

        $discoveredSymbols = new DiscoveredSymbols();
        $sut = new FileSymbolScanner($config, $discoveredSymbols, $filesystem);

        $file = new File('/a/path.php', 'path.php', '/a/path.php');

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $sut->findInFiles($discoveredFiles);

        $codeMap = $file->getCodeMap();
        self::assertNotNull($codeMap);
        self::assertTrue($codeMap->matchesContent($contents));

        // What the file contains.
        $definitions = $codeMap->getDefinitionsByType();
        $names = fn(array $definitions): array => array_map(fn(SymbolDefinition $d): string => $d->getName(), $definitions);

        self::assertSame(['Acme\Widgets'], $names($definitions[SymbolDefinition::TYPE_NAMESPACE]));
        self::assertSame(['Acme\Widgets\Widget'], $names($definitions[SymbolDefinition::TYPE_CLASS]));
        self::assertSame(['Acme\Widgets\HasName'], $names($definitions[SymbolDefinition::TYPE_INTERFACE]));
        self::assertSame(['Acme\Widgets\Named'], $names($definitions[SymbolDefinition::TYPE_TRAIT]));
        self::assertSame(['Acme\Widgets\Kind'], $names($definitions[SymbolDefinition::TYPE_ENUM]));
        self::assertSame(['Acme\Widgets\make'], $names($definitions[SymbolDefinition::TYPE_FUNCTION]));
        self::assertSame(['DEFAULT_KIND', 'ACME_LOADED'], $names($definitions[SymbolDefinition::TYPE_CONSTANT]));

        $class = $definitions[SymbolDefinition::TYPE_CLASS][0];
        self::assertSame('Acme\Base\Model', $class->getExtends());
        self::assertSame(['Acme\Widgets\HasName', 'Countable'], $class->getInterfaces());

        // What the file uses.
        $usages = $codeMap->getUsagesByType();
        $texts = fn(array $locations): array => array_map(fn(CodeLocation $l): string => $l->getText(), $locations);

        self::assertSame(
            ['Acme\Base\Model', 'Acme\Helpers\format', 'Acme\Helpers\VERSION'],
            $texts($usages[CodeLocation::USE_NAME])
        );
        self::assertSame(['Model', 'HasName', '\Countable', 'Named', 'Kind', 'Widget', 'Widget'], $texts($usages[CodeLocation::NAME]));
        self::assertSame(['define', 'format', '\strlen'], $texts($usages[CodeLocation::FUNCTION_CALL]));
        // In source order: `define('ACME_LOADED', true)` precedes the class body.
        self::assertSame(['true', 'VERSION'], $texts($usages[CodeLocation::CONST_FETCH]));
        self::assertSame(['ACME_LOADED'], $texts($usages[CodeLocation::DEFINE_STRING_ARG]));

        self::assertContains('Acme\Base\Model', $codeMap->getUsedNames());
        self::assertContains('strlen', $codeMap->getUsedNames());

        // The same symbols were registered globally.
        self::assertNotNull($discoveredSymbols->getClass('Acme\Widgets\Widget'));
        self::assertNotNull($discoveredSymbols->getInterface('Acme\Widgets\HasName'));
        self::assertNotNull($discoveredSymbols->getTrait('Acme\Widgets\Named'));
        self::assertNotNull($discoveredSymbols->getEnum('Acme\Widgets\Kind'));
        self::assertNotNull($discoveredSymbols->getFunction('Acme\Widgets\make'));
        self::assertNotNull($discoveredSymbols->getConst('Acme\Widgets\DEFAULT_KIND'));
        self::assertNotNull($discoveredSymbols->getNamespace('Acme\Widgets'));
    }

    /**
     * A file which cannot be parsed gets no code map; the scan continues.
     *
     * @covers ::findInFiles
     */
    public function testUnparsableFileHasNoCodeMap(): void
    {
        $this->expectWarningLogs();

        $filesystem = Mockery::mock(FileSystem::class);
        $filesystem->expects('read')->once()->andReturn("<?php namespace %g_namespace%\\AdminMenus;\n");
        $filesystem->expects('getRelativePath')->once()->andReturnArg(1);

        $config = $this->createMock(StraussConfig::class);
        $config->method('getPackagesToPrefix')->willReturn([]);

        $sut = new FileSymbolScanner($config, new DiscoveredSymbols(), $filesystem, $this->getLogger());

        $file = new File('/a/template.php', 'template.php', '/a/template.php');

        $discoveredFiles = Mockery::mock(DiscoveredFiles::class);
        $discoveredFiles->shouldReceive('getFiles')->andReturn([$file]);

        $sut->findInFiles($discoveredFiles);

        self::assertNull($file->getCodeMap());
        self::assertTrue($this->getTestLogger()->hasWarningThatContains('Failed to parse file'));
    }
}
