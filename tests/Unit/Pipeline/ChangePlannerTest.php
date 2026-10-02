<?php

namespace BrianHenryIE\Strauss\Tests\Unit\Pipeline;

use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileCodeMap;
use BrianHenryIE\Strauss\Pipeline\ChangePlanner;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\PhpFileAnalyzer;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\ChangePlanner
 */
class ChangePlannerTest extends TestCase
{
    protected function getFile(): File
    {
        return new File(
            'vendor/package/name/src/file.php',
            'package/name/src/file.php',
            'vendor-prefixed/package/name/src/file.php',
        );
    }

    /**
     * @covers ::plan
     */
    public function test_overlapping_positions_throw(): void
    {
        $planner = new class () extends ChangePlanner {
            protected function findConstantPositions(FileCodeMap $codeMap, DiscoveredSymbols $discoveredSymbols): array
            {
                return [
                    ['start' => 6, 'end' => 20, 'replacement' => 'outer'],
                    ['start' => 10, 'end' => 15, 'replacement' => 'inner'],
                ];
            }
        };

        $contents = "<?php\n\$a = 'some string here';\n";
        $codeMap = (new PhpFileAnalyzer())->analyze($contents);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Overlapping replacement');

        $planner->plan(new DiscoveredSymbols(), $codeMap, $this->getFile());
    }

    /**
     * The plan is stored on the file, ascending by position, before any file is touched.
     *
     * @covers ::plan
     * @covers ::planInFiles
     */
    public function test_plan_is_stored_on_file(): void
    {
        $contents = <<<'EOD'
<?php

namespace Acme\Widgets;

use Acme\Widgets\Widget;

$w = new Widget();
$name = 'Acme\Widgets\Widget';
EOD;

        $file = $this->getFile();
        $codeMap = (new PhpFileAnalyzer())->analyze($contents);
        $file->setCodeMap($codeMap);

        $namespace = new NamespaceSymbol('Acme\Widgets', $file);
        $namespace->setDoRename(true);
        $namespace->setLocalReplacement('Prefix\Acme\Widgets');
        $classSymbol = new ClassSymbol('Acme\Widgets\Widget', $file, $namespace);
        $classSymbol->setDoRename(true);
        $discoveredSymbols = new DiscoveredSymbols([$namespace, $classSymbol]);

        self::assertNull($file->getPlannedEdits());

        (new ChangePlanner())->planInFiles($discoveredSymbols, [$file]);

        $edits = $file->getPlannedEdits();

        self::assertNotNull($edits);

        self::assertCount(3, $edits);

        $starts = array_column($edits, 'start');
        $sorted = $starts;
        sort($sorted);
        self::assertSame($sorted, $starts, 'Edits should be ascending by start.');

        self::assertSame('Prefix\Acme\Widgets', $edits[0]['replacement']);
        self::assertSame('Prefix\Acme\Widgets\Widget', $edits[1]['replacement']);
        self::assertSame('Prefix\Acme\Widgets\Widget', $edits[2]['replacement']);
    }

    /**
     * Files without a code map (non-PHP, unparseable) are skipped.
     *
     * @covers ::planInFiles
     */
    public function test_files_without_code_map_are_skipped(): void
    {
        $file = $this->getFile();

        (new ChangePlanner())->planInFiles(new DiscoveredSymbols(), [$file]);

        self::assertNull($file->getCodeMap());
    }
}
