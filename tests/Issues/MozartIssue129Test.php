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
use BrianHenryIE\Strauss\Pipeline\Prefixer;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

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
    public function test_test($phpString, $expected)
    {

        $config = $this->createMock(StraussConfig::class);

        $original = 'Example\Sdk\Endpoints';
        $replacement = 'Strauss\Example\Sdk\Endpoints';

        $replacer = new Prefixer($config, __DIR__, new Filesystem(new \League\Flysystem\Filesystem(new LocalFilesystemAdapter('/'))));

        $result = $replacer->replaceNamespace($phpString, $original, $replacement);

        self::assertEqualsRN($expected, $result);
    }

    public static function pairTestDataProvider()
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
