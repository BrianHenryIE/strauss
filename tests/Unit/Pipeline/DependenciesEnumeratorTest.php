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
        foreach (
            [
                [
                    'name' => 'acme/app',
                    'require' => ['psr/log-implementation' => '*', 'acme/original' => '*', 'acme/real' => '*'],
                    'suggest' => ['acme/optional' => 'For optional things', 'acme/not-installed' => 'Absent'],
                ],
                ['name' => 'acme/logger', 'provide' => ['psr/log-implementation' => '1.0']],
                ['name' => 'acme/fork', 'replace' => ['acme/original' => '*']],
                ['name' => 'acme/real'],
                ['name' => 'acme/optional'],
            ] as $composerJsonArray
        ) {
            $packages[$composerJsonArray['name']] = ComposerPackage::fromComposerJsonArray($composerJsonArray);
        }

        $config = Mockery::mock(StraussConfig::class);
        $config->shouldReceive('getOverrideAutoload')->andReturn([]);
        $config->shouldReceive('getPackageNames')->andReturn(['acme/app']);

        $sut = new class ($config, $this->getInMemoryFileSystem(), $packages) extends DependenciesEnumerator {
            /**
             * @param array<string,ComposerPackage> $packages
             */
            public function __construct($config, $filesystem, array $packages)
            {
                parent::__construct($config, $filesystem);
                $this->flatDependencyArray = $packages;
            }

            protected function recursiveGetAllDependencies(array $requiredPackageNames): void {}
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

    /**
     * @return DependenciesEnumerator&object{loaded: string[]}
     */
    protected function getUnprefixedPackagesSut(bool $isTargetDirectoryVendor): DependenciesEnumerator
    {
        $psrLog = ComposerPackage::fromComposerJsonArray(['name' => 'psr/log']);
        $excluded = Mockery::mock(ComposerPackage::class);
        $excluded->shouldReceive('getPackageAbsolutePath')->andReturn('/project/vendor/acme/excluded');
        $monolog = Mockery::mock(ComposerPackage::class);
        $monolog->shouldReceive('getPackageAbsolutePath')->andReturn('/project/vendor/monolog/monolog');
        $metapackage = Mockery::mock(ComposerPackage::class);
        $metapackage->shouldReceive('getPackageAbsolutePath')->andReturnNull();

        $installedJson = [
            'packages' => array_map(
                fn(string $name): array => ['name' => $name],
                [
                    'psr/log',
                    'acme/excluded',
                    'monolog/monolog',
                    'acme/metapackage',
                    'acme/not-loadable',
                    'brianhenryie/strauss',
                    'phpunit/phpunit',
                ]
            ),
            'dev-package-names' => ['phpunit/phpunit'],
        ];

        $config = Mockery::mock(StraussConfig::class);
        $config->shouldReceive('getOverrideAutoload')->andReturn([]);
        $config->shouldReceive('getPackageNames')->andReturn(['psr/log']);
        $config->shouldReceive('getPackagesToPrefix')->andReturn(['psr/log' => $psrLog]);
        $config->shouldReceive('getExcludePackagesFromPrefixing')->andReturn(['acme/excluded']);
        $config->shouldReceive('isTargetDirectoryVendor')->andReturn($isTargetDirectoryVendor);

        // psr/log and acme/excluded are in the dependency tree.
        $flatDependencyArray = ['psr/log' => $psrLog, 'acme/excluded' => $excluded];
        $loadablePackages = ['monolog/monolog' => $monolog, 'acme/metapackage' => $metapackage];

        return new class ($config, $this->getInMemoryFileSystem(), $installedJson, $flatDependencyArray, $loadablePackages) extends DependenciesEnumerator {
            /** @var array<string, mixed> */
            protected array $installedJson;

            /** @var array<string, ComposerPackage> */
            protected array $loadablePackages;

            /** @var string[] */
            public array $loaded = [];

            /**
             * @param array<string, mixed>           $installedJson
             * @param array<string, ComposerPackage> $flatDependencyArray
             * @param array<string, ComposerPackage> $loadablePackages
             */
            public function __construct($config, $filesystem, array $installedJson, array $flatDependencyArray, array $loadablePackages)
            {
                parent::__construct($config, $filesystem);
                $this->installedJson = $installedJson;
                $this->flatDependencyArray = $flatDependencyArray;
                $this->loadablePackages = $loadablePackages;
            }

            protected function readInstalledJson(): array
            {
                /** @phpstan-ignore return.type */
                return $this->installedJson;
            }

            protected function getInstalledJsonPackages(): array
            {
                return [];
            }

            protected function loadPackage(string $requiredPackageName, array $installedJsonPackages): ?ComposerPackage
            {
                $this->loaded[] = $requiredPackageName;
                return $this->loadablePackages[$requiredPackageName] ?? null;
            }
        };
    }

    /**
     * In place, every non-dev installed package not being prefixed: those already loaded as dependencies (e.g.
     * excluded from prefixing) are reused, others are loaded. Dev, platform and metapackages, and Strauss itself, are
     * not included.
     *
     * @covers ::getUnprefixedPackages
     * @covers ::getInstalledUnprefixedPackages
     * @covers ::getNonDevInstalledPackageNames
     */
    public function testGetUnprefixedPackages(): void
    {
        $sut = $this->getUnprefixedPackagesSut(true);

        $result = $sut->getUnprefixedPackages();

        self::assertSame(['acme/excluded', 'monolog/monolog'], array_keys($result));
        self::assertSame(['monolog/monolog', 'acme/metapackage', 'acme/not-loadable'], $sut->loaded);
    }

    /**
     * With another target directory, only the dependency tree's packages excluded from prefixing: the other installed
     * packages stay in `vendor`, unchanged.
     *
     * @covers ::getUnprefixedPackages
     */
    public function testGetUnprefixedPackagesWithAnotherTargetDirectory(): void
    {
        $sut = $this->getUnprefixedPackagesSut(false);

        $result = $sut->getUnprefixedPackages();

        self::assertSame(['acme/excluded'], array_keys($result));
        self::assertSame([], $sut->loaded);
    }
}
