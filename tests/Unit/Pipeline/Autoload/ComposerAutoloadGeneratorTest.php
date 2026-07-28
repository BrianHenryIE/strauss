<?php

namespace BrianHenryIE\Strauss\Pipeline\Autoload;

use Composer\EventDispatcher\EventDispatcher;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\Autoload\ComposerAutoloadGenerator
 */
class ComposerAutoloadGeneratorTest extends \BrianHenryIE\Strauss\TestCase
{
    /**
     * @covers ::getFileIdentifier
     */
    public function testGetFileIdentifier(): void
    {
        $eventDispatcher = Mockery::mock(EventDispatcher::class);

        $package = Mockery::mock(PackageInterface::class);
        $package->expects('getAutoload')->times(10)->andReturn(
            [
                'files' => [
                    'functions.php',
                ],
            ]
        );
        $package->expects('getName')->times(8)->andReturn('my/package');
        $package->expects('getRequires')->times(2)->andReturn([]);
        $package->expects('getTargetDir')->times(8)->andReturn('my/package');

        $rootPackage = Mockery::mock(RootPackageInterface::class);
        $rootPackage->expects('getAutoload')->times(10)->andReturn([]);

        $packageMap = [
            [$rootPackage, ''],
            [$package, 'my/package']
        ];

        $getFileIdentifier = function (string $projectUniqueString) use ($eventDispatcher, $packageMap, $rootPackage) {

            $sut = new ComposerAutoloadGenerator(
                $projectUniqueString,
                $eventDispatcher
            );
            $sut->setDryRun();
            $sut->setDevMode(false);

            $autoloadArraysResult = $sut->parseAutoloads(
                $packageMap,
                $rootPackage
            );

            return array_search('my/package/functions.php', $autoloadArraysResult['files'], true);
        };

        $fileIdentifier1 = $getFileIdentifier('project1');
        $fileIdentifier2 = $getFileIdentifier('project2');

        $this->assertNotEquals($fileIdentifier1, $fileIdentifier2);
    }
}
