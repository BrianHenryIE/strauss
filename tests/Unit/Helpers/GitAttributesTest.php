<?php

namespace BrianHenryIE\Strauss\Helpers;

use BrianHenryIE\Strauss\TestCase;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Helpers\GitAttributes
 */
class GitAttributesTest extends TestCase
{
    /**
     * @covers ::parse
     */
    public function test_parse_returns_patterns_and_attributes(): void
    {
        $gitAttributes = <<<'EOD'
# A comment line which should be ignored.

/tests       export-ignore
phpunit.xml  export-ignore
*.dist       export-ignore
src/Keep.php -export-ignore
* text=auto
EOD;

        $filesystem = $this->getInMemoryFileSystem();
        $filesystem->write('mem://package/.gitattributes', $gitAttributes);

        $sut = new GitAttributes($filesystem, 'mem://package');

        $parsed = $sut->parse();

        $expected = [
            ['pattern' => '/tests', 'attributes' => ['export-ignore' => true]],
            ['pattern' => 'phpunit.xml', 'attributes' => ['export-ignore' => true]],
            ['pattern' => '*.dist', 'attributes' => ['export-ignore' => true]],
            ['pattern' => 'src/Keep.php', 'attributes' => ['export-ignore' => false]],
            ['pattern' => '*', 'attributes' => ['text' => 'auto']],
        ];

        $this->assertEquals($expected, $parsed);
    }

    /**
     * @covers ::parse
     */
    public function test_parse_returns_empty_array_when_no_gitattributes_file(): void
    {
        $filesystem = $this->getInMemoryFileSystem();
        $filesystem->write('mem://package/src/Foo.php', '<?php');

        $sut = new GitAttributes($filesystem, 'mem://package');

        $this->assertSame([], $sut->parse());
    }

    /**
     * @covers ::isExportIgnored
     */
    public function test_is_export_ignored_matches_directories_files_and_wildcards(): void
    {
        $gitAttributes = <<<'EOD'
/tests      export-ignore
phpunit.xml export-ignore
*.dist      export-ignore
EOD;

        $filesystem = $this->getInMemoryFileSystem();
        $filesystem->write('mem://package/.gitattributes', $gitAttributes);

        $sut = new GitAttributes($filesystem, 'mem://package');

        // Directory pattern excludes its contents.
        $this->assertTrue($sut->isExportIgnored('tests/Unit/FooTest.php'));
        // Exact file match.
        $this->assertTrue($sut->isExportIgnored('phpunit.xml'));
        // Wildcard match at any depth.
        $this->assertTrue($sut->isExportIgnored('config/services.dist'));

        // Files that should be kept.
        $this->assertFalse($sut->isExportIgnored('src/Foo.php'));
        $this->assertFalse($sut->isExportIgnored('README.md'));
    }

    /**
     * @covers ::isExportIgnored
     */
    public function test_is_export_ignored_later_unset_reincludes_path(): void
    {
        $gitAttributes = <<<'EOD'
/tests           export-ignore
/tests/Keep.php  -export-ignore
EOD;

        $filesystem = $this->getInMemoryFileSystem();
        $filesystem->write('mem://package/.gitattributes', $gitAttributes);

        $sut = new GitAttributes($filesystem, 'mem://package');

        $this->assertTrue($sut->isExportIgnored('tests/Removed.php'));
        $this->assertFalse($sut->isExportIgnored('tests/Keep.php'));
    }
}
