<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\TestCase;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\DependenciesEnumerator
 */
class DependenciesEnumeratorTest extends TestCase
{
    /**
     * A requirement for a virtual or replaced package is an edge to the installed packages which `provide` or
     * `replace` it, and a `suggest`ed package that is installed is recorded as suggested.
     *
     * @covers ::getAllDependencies
     */
    public function testProvideReplaceAndSuggestAreLinked(): void
    {
        $packages = [];
        foreach ([
            [
                'name' => 'acme/app',
                'require' => ['psr/log-implementation' => '*', 'acme/original' => '*', 'acme/real' => '*'],
                'suggest' => ['acme/optional' => 'For optional things', 'acme/not-installed' => 'Absent'],
            ],
            ['name' => 'acme/logger', 'provide' => ['psr/log-implementation' => '1.0']],
            ['name' => 'acme/fork', 'replace' => ['acme/original' => '*']],
            ['name' => 'acme/real'],
            ['name' => 'acme/optional'],
        ] as $composerJsonArray) {
            $packages[$composerJsonArray['name']] = ComposerPackage::fromComposerJsonArray($composerJsonArray);
        }

        $config = Mockery::mock(StraussConfig::class);
        $config->shouldReceive('getOverrideAutoload')->andReturn([]);
        $config->shouldReceive('getPackages')->andReturn(['acme/app']);

        $sut = new class ($config, $this->getInMemoryFileSystem(), $packages) extends DependenciesEnumerator {
            /**
             * @param array<string,ComposerPackage> $packages
             */
            public function __construct($config, $filesystem, array $packages)
            {
                parent::__construct($config, $filesystem);
                $this->flatDependencyArray = $packages;
            }

            protected function recursiveGetAllDependencies(array $requiredPackageNames): void
            {
            }
        };

        $sut->getAllDependencies();

        $app = $packages['acme/app'];

        self::assertSame(['psr/log-implementation'], $packages['acme/logger']->getProvidesNames());
        self::assertSame(['acme/original'], $packages['acme/fork']->getProvidesNames());
        self::assertSame(['acme/optional', 'acme/not-installed'], $app->getSuggestsNames());

        $dependencyNames = array_keys($app->getDependencies());
        sort($dependencyNames);
        self::assertSame(['acme/fork', 'acme/logger', 'acme/real'], $dependencyNames);

        self::assertSame(['acme/optional'], array_keys($app->getSuggestedPackages()));
    }
}
