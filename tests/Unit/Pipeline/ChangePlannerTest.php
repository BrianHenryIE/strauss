<?php

namespace BrianHenryIE\Strauss\Tests\Unit\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileWithDependency;
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
     * Files without a code map (non-PHP, unparsable) are skipped.
     *
     * @covers ::planInFiles
     */
    public function test_files_without_code_map_are_skipped(): void
    {
        $file = $this->getFile();

        (new ChangePlanner())->planInFiles(new DiscoveredSymbols(), [$file]);

        self::assertNull($file->getCodeMap());
    }

    /**
     * @param array<string,string> $require
     */
    protected function getPackage(string $name, array $require = []): ComposerPackage
    {
        $package = ComposerPackage::fromComposerJsonArray(['name' => $name, 'require' => $require]);
        $package->setPackageAbsolutePath('/project/vendor/' . $name . '/');
        return $package;
    }

    protected function getPackageFile(ComposerPackage $package): FileWithDependency
    {
        $name = $package->getPackageName();
        return new FileWithDependency(
            $package,
            $name . '/src/file.php',
            '/project/vendor/' . $name . '/src/file.php',
            '/project/vendor-prefixed/' . $name . '/src/file.php'
        );
    }

    /**
     * A string in a package's file can only refer to symbols of that package and of the packages it requires,
     * directly or transitively, or suggests.
     *
     * @covers ::plan
     * @covers ::findSymbolsPositionsInStrings
     * @covers ::filterToSymbolsAvailableToFile
     * @covers ::getAvailablePackageNames
     */
    public function test_strings_only_match_symbols_from_required_packages(): void
    {
        $unrelated = $this->getPackage('acme/unrelated');
        $deepest = $this->getPackage('acme/deepest');
        $deep = $this->getPackage('acme/deep', ['acme/deepest' => '*']);
        $deep->addDependency($deepest);
        $direct = $this->getPackage('acme/direct', ['acme/deep' => '*']);
        $direct->addDependency($deep);
        $own = $this->getPackage('acme/own', ['acme/direct' => '*']);
        $own->addDependency($direct);
        $suggested = $this->getPackage('acme/suggested');
        $own->addSuggestedPackage($suggested);

        $symbols = [];
        foreach ([
            'Own' => $own,
            'Direct' => $direct,
            'Deep' => $deep,
            'Deepest' => $deepest,
            'Unrelated' => $unrelated,
            'Suggested' => $suggested,
        ] as $namespaceName => $package) {
            $namespace = new NamespaceSymbol($namespaceName, $this->getPackageFile($package));
            $namespace->setDoRename(true);
            $namespace->setLocalReplacement('Prefix\\' . $namespaceName);
            $symbols[] = $namespace;
        }
        // Not from any package: always searched for.
        $unknown = new NamespaceSymbol('Unknown', $this->getFile());
        $unknown->setDoRename(true);
        $unknown->setLocalReplacement('Prefix\Unknown');
        $symbols[] = $unknown;

        $discoveredSymbols = new DiscoveredSymbols($symbols);

        $contents = <<<'EOD'
<?php
$a = ['Own\\', 'Direct\\', 'Deep\\', 'Deepest\\', 'Unrelated\\', 'Suggested\\', 'Unknown\\'];
EOD;

        $planFor = function (File $file) use ($contents, $discoveredSymbols): array {
            $codeMap = (new PhpFileAnalyzer())->analyze($contents);
            return array_column((new ChangePlanner())->plan($discoveredSymbols, $codeMap, $file), 'replacement');
        };

        self::assertSame(
            ['Prefix\Own', 'Prefix\Direct', 'Prefix\Deep', 'Prefix\Deepest', 'Prefix\Suggested', 'Prefix\Unknown'],
            $planFor($this->getPackageFile($own))
        );

        // A dependency does not know about the packages which require it.
        self::assertSame(
            ['Prefix\Deepest', 'Prefix\Unknown'],
            $planFor($this->getPackageFile($deepest))
        );

        // A file which is not part of a package (e.g. a project file) may refer to anything.
        self::assertCount(7, $planFor($this->getFile()));
    }
}
