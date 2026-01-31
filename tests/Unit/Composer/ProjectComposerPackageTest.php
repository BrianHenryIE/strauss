<?php

namespace BrianHenryIE\Strauss\Composer;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\TestCase;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Composer\ProjectComposerPackage
 */
class ProjectComposerPackageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createWorkingDir();
    }

    /**
     * A simple test to check the getters all work.
     */
    public function testParseJson()
    {

        $testFile = __DIR__ . '/projectcomposerpackage-test-1.json';

        copy($testFile, $this->testsWorkingDir . 'composer.json');

        $composer = new ProjectComposerPackage($this->testsWorkingDir . 'composer.json');

        $config = $composer->getStraussConfig();

        self::assertInstanceOf(StraussConfig::class, $config);
    }

    /**
     * @covers ::getFlatAutoloadKey
     */
    public function testGetFlatAutoloadKey()
    {

        $testFile = __DIR__ . '/projectcomposerpackage-test-getProjectPhpFiles.json';

        copy($testFile, $this->testsWorkingDir . 'composer.json');

        $composer = new ProjectComposerPackage($this->testsWorkingDir . 'composer.json');

        $phpFiles = $composer->getFlatAutoloadKey();

        $expected = ["src","includes","classes","functions.php"];

        self::assertEqualsRN($expected, $phpFiles);
    }
}
