<?php
/**
 * Namespaces with escaped backslashes in strings are not replaced.
 *
 * @see https://github.com/coenjacobs/mozart/issues/129
 *
 * Also affects mpdf: Tag.php:170
 *
 * $className = 'Mpdf\Tag\\';
 *
 * @author BrianHenryIE
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Pipeline\Prefixer;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;

/**
 * Class MozartIssue129Test
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class MozartIssue129Test extends TestCase
{

    /**
     * @author BrianHenryIE
     *
     * @dataProvider pairTestDataProvider
     */
    public function test_test(string $phpString, string $expected): void
    {
        $config = $this->createMock(StraussConfig::class);

        $filesystem = $this->getInMemoryFileSystem();

        $original = 'Example\Sdk\Endpoints';
        $replacement = 'Strauss\Example\Sdk\Endpoints';

        $namespaceSymbol = new NamespaceSymbol($original);
        $namespaceSymbol->setDoRename(true);
        $namespaceSymbol->setLocalReplacement($replacement);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $file = new File(
            'sourceAbsolutePath.php',
            'vendorRelativePath.php',
            'targetAbsolutePath.php'
        );

        $replacer = new Prefixer($config, $filesystem);

        $filesystem->write($file->getTargetAbsolutePath(), $phpString);

        $replacer->replaceInFiles($discoveredSymbols, [$file]);

        $result = $filesystem->read($file->getTargetAbsolutePath());

        $this->assertEqualsRN($expected, $result);
    }

    /**
     * @return array<array{0:string, 1:string}>
     */
    public static function pairTestDataProvider(): array
    {
        $fromTo = [];

        $contents = <<<'EOD'
$baseNamespace = "\Example\Sdk\Endpoints";
EOD;
        $expected = <<<'EOD'
$baseNamespace = "\Strauss\Example\Sdk\Endpoints";
EOD;
        $fromTo[] = [ $contents, $expected];

        $contents = <<<'EOD'
$baseNamespace = "Example\\Sdk\\Endpoints";
EOD;
        $expected = <<<'EOD'
$baseNamespace = "Strauss\\Example\\Sdk\\Endpoints";
EOD;
        $fromTo[] = [ $contents, $expected];

        $contents = <<<'EOD'
$baseNamespace = "Example\Sdk\Endpoints";
EOD;
        $expected = <<<'EOD'
$baseNamespace = "Strauss\Example\Sdk\Endpoints";
EOD;
        $fromTo[] = [ $contents, $expected];

        $contents = <<<'EOD'
$baseNamespace = '\\Example\\Sdk\\Endpoints';
EOD;
        $expected = <<<'EOD'
$baseNamespace = '\\Strauss\\Example\\Sdk\\Endpoints';
EOD;
        $fromTo[] = [ $contents, $expected];

        $contents = <<<'EOD'
$baseNamespace = '\Example\Sdk\Endpoints';
EOD;
        $expected = <<<'EOD'
$baseNamespace = '\Strauss\Example\Sdk\Endpoints';
EOD;
        $fromTo[] = [ $contents, $expected];

        $contents = <<<'EOD'
$baseNamespace = 'Example\\Sdk\\Endpoints';
EOD;
        $expected = <<<'EOD'
$baseNamespace = 'Strauss\\Example\\Sdk\\Endpoints';
EOD;
        $fromTo[] = [ $contents, $expected];

        $contents = <<<'EOD'
$baseNamespace = 'Example\Sdk\Endpoints';
EOD;
        $expected = <<<'EOD'
$baseNamespace = 'Strauss\Example\Sdk\Endpoints';
EOD;
        $fromTo[] = [ $contents, $expected];

        return $fromTo;
    }
}
