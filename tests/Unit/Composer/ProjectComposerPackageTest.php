<?php

namespace BrianHenryIE\Strauss\Composer;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\TestCase;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Composer\ProjectComposerPackage
 */
class ProjectComposerPackageTest extends TestCase
{
    /**
     * A simple test to check the getters all work.
     */
    public function testParseJson(): void
    {
        $this->getFileSystem()->write(
            'project/composer.json',
            $this->getFixturesFilesystem()->read(__DIR__ . '/projectcomposerpackage-test-1.json')
        );

        $composer = new ProjectComposerPackage('mem://project/composer.json');

        $config = $composer->getStraussConfig();

        $this->assertInstanceOf(StraussConfig::class, $config);
    }

    /**
     * @covers ::getFlatAutoloadKey
     */
    public function testGetFlatAutoloadKey(): void
    {
        $this->getFileSystem()->write(
            'project/composer.json',
            $this->getFixturesFilesystem()->read(
                __DIR__ . '/projectcomposerpackage-test-getProjectPhpFiles.json'
            )
        );

        $composer = new ProjectComposerPackage(
            'mem://project/composer.json'
        );

        $phpFiles = $composer->getFlatAutoloadKey();

        $expected = ["src","includes","classes","functions.php"];

        $this->assertEqualsRN($expected, $phpFiles);
    }
}
