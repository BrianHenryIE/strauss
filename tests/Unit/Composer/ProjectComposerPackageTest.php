<?php

namespace BrianHenryIE\Strauss\Composer;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Composer\ProjectComposerPackage;
use BrianHenryIE\Strauss\TestCase;
use Symfony\Component\Console\Input\InputInterface;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Composer\ProjectComposerPackage
 */
class ProjectComposerPackageTest extends TestCase
{

    /**
     * A simple test to check the getters all work.
     */
    public function testParseJson()
    {

        $testFile = __DIR__ . '/projectcomposerpackage-test-1.json';

        $composer = new ProjectComposerPackage($testFile);

        $input = $this->createMock(InputInterface::class);
        $config = $composer->getStraussConfig($input);

        self::assertInstanceOf(StraussConfig::class, $config);
    }

    /**
     * @covers ::getFlatAutoloadKey
     */
    public function testGetFlatAutoloadKey()
    {

        $testFile = __DIR__ . '/projectcomposerpackage-test-getProjectPhpFiles.json';

        $composer = new ProjectComposerPackage($testFile);

        $phpFiles = $composer->getFlatAutoloadKey();

        $expected = ["src","includes","classes","functions.php"];

        self::assertEqualsRN($expected, $phpFiles);
    }
}
