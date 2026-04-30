<?php

namespace BrianHenryIE\Strauss\Pipeline\Cleanup;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\CleanupConfigInterface;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\Cleanup\InstalledJson
 */
class InstalledJsonTest extends \BrianHenryIE\Strauss\TestCase
{


    public function test_remove_dead_file_entries(): void
    {
        $this->markTestSkipped('TODO');

        $fileSystem = $this->getInMemoryFileSystem();
        $config = Mockery::mock(CleanupConfigInterface::class);

        $sut = new InstalledJson(
            $config,
            $fileSystem,
            new NullLogger()
        );

        $flatDependencyTree = [];
        $discoveredSymbols = new DiscoveredSymbols();

        $sut->cleanupVendorInstalledJson($flatDependencyTree, $discoveredSymbols);
    }

    public function test_updates_nothing(): void
    {
        $this->markTestSkipped('TODO');

        $installedJson = <<<'EOD'
{"packages":[{"name":"psr\/container","version":"1.1.2","version_normalized":"1.1.2.0","source":{"type":"git","url":"https:\/\/github.com\/php-fig\/container.git","reference":"513e0666f7216c7459170d56df27dfcefe1689ea"},"dist":{"type":"zip","url":"https:\/\/api.github.com\/repos\/php-fig\/container\/zipball\/513e0666f7216c7459170d56df27dfcefe1689ea","reference":"513e0666f7216c7459170d56df27dfcefe1689ea","shasum":""},"require":{"php":">=7.4.0"},"time":"2021-11-05T16:50:12+00:00","type":"library","installation-source":"dist","autoload":{"psr-4":{"Psr\\Container\\":"src\/"}},"notification-url":"https:\/\/packagist.org\/downloads\/","license":["MIT"],"authors":[{"name":"PHP-FIG","homepage":"https:\/\/www.php-fig.org\/"}],"description":"Common Container Interface (PHP FIG PSR-11)","homepage":"https:\/\/github.com\/php-fig\/container","keywords":["PSR-11","container","container-interface","container-interop","psr"],"support":{"issues":"https:\/\/github.com\/php-fig\/container\/issues","source":"https:\/\/github.com\/php-fig\/container\/tree\/1.1.2"},"install-path":"..\/psr\/container"}],"dev":true,"dev-package-names":[]}
EOD;

        $fileSystem = $this->getInMemoryFileSystem();

        $fileSystem->write('vendor/composer/installed.json', $installedJson);

        $config = Mockery::mock(CleanupConfigInterface::class);
        $config->expects()->isDryRun()->once()->andReturn(true);
        $config->expects()->getAbsoluteVendorDirectory()->once()->andReturn('vendor');

        $sut = new InstalledJson(
            $config,
            $fileSystem,
            new NullLogger()
        );

        // NO CHANGES.
        $flatDependencyTree = [];
        $discoveredSymbols = new DiscoveredSymbols();

        $sut->cleanupVendorInstalledJson($flatDependencyTree, $discoveredSymbols);

        $this->assertEqualsRemoveBlankLinesLeadingWhitespace($installedJson, json_encode(json_decode($fileSystem->read('vendor/composer/installed.json'))));
    }

    /**
     * @covers ::cleanupVendorInstalledJson
     * @covers ::removeMissingAutoloadKeyPaths
     * @covers ::updateNamespaces
     */
    public function test_updates_path(): void
    {

        $installedJson = <<<'EOD'
{
    "packages": [
        {
            "name": "psr\/container",
            "version": "1.1.2",
            "version_normalized": "1.1.2.0",
            "source": {
                "type": "git",
                "url": "https:\/\/github.com\/php-fig\/container.git",
                "reference": "513e0666f7216c7459170d56df27dfcefe1689ea"
            },
            "dist": {
                "type": "zip",
                "url": "https:\/\/api.github.com\/repos\/php-fig\/container\/zipball\/513e0666f7216c7459170d56df27dfcefe1689ea",
                "reference": "513e0666f7216c7459170d56df27dfcefe1689ea",
                "shasum": ""
            },
            "require": {
                "php": ">=7.4.0"
            },
            "time": "2021-11-05T16:50:12+00:00",
            "type": "library",
            "installation-source": "dist",
            "autoload": {
                "psr-4": {
                    "Psr\\Container\\": "src\/"
                }
            },
            "notification-url": "https:\/\/packagist.org\/downloads\/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "PHP-FIG",
                    "homepage": "https:\/\/www.php-fig.org\/"
                }
            ],
            "description": "Common Container Interface (PHP FIG PSR-11)",
            "homepage": "https:\/\/github.com\/php-fig\/container",
            "keywords": [
                "PSR-11",
                "container",
                "container-interface",
                "container-interop",
                "psr"
            ],
            "support": {
                "issues": "https:\/\/github.com\/php-fig\/container\/issues",
                "source": "https:\/\/github.com\/php-fig\/container\/tree\/1.1.2"
            },
            "install-path": "..\/psr\/container"
        }
    ],
    "dev": true,
    "dev-package-names": []
}
EOD;

        $fileSystem = $this->getInMemoryFileSystem();

        $fileSystem->createDirectory('vendor/composer');
        $fileSystem->write('vendor/composer/installed.json', $installedJson);
        $fileSystem->write('vendor-prefixed/psr/container/src/ContainerInterface.php', '<?php namespace Psr\Container;');
        $fileSystem->createDirectory('vendor/psr/container');
        $fileSystem->createDirectory('vendor/psr/container/src');

        $config = Mockery::mock(CleanupConfigInterface::class);
        $config->expects('getAbsoluteVendorDirectory')->atLeast()->once()->andReturn('vendor');
        $config->shouldReceive('getExcludePackagesFromCopy')->andReturn([]);
        $config->shouldReceive('isDryRun')->andReturnFalse();

        $sut = new InstalledJson(
            $config,
            $fileSystem,
            new NullLogger()
        );

        /** @var ComposerPackage|MockInterface $composerPackageMock */
        $composerPackageMock = Mockery::mock(ComposerPackage::class);
        $composerPackageMock->expects('didDelete')->once()->andReturnFalse();

        /** @var array<string,ComposerPackage> $flatDependencyTree*/
        $flatDependencyTree = ['psr/container'=> $composerPackageMock];

        $file = Mockery::mock(FileWithDependency::class);
        $file->expects('getSourcePath')->andReturn('vendor/psr/container/src/ContainerInterface.php');
        $file->expects('addDiscoveredSymbol');

        $namespaceSymbol = new NamespaceSymbol('Psr\\Container', $file);
        $namespaceSymbol->setReplacement('BrianHenryIE\\Tests\\Psr\\Container',);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $sut->cleanupVendorInstalledJson($flatDependencyTree, $discoveredSymbols);

        $this->assertStringContainsString('"BrianHenryIE\\\\Tests\\\\Psr\\\\Container\\\\": "src/"', $fileSystem->read('vendor/composer/installed.json'));
        $this->assertStringNotContainsString('"Psr\\\\Container\\\\": "src/"', $fileSystem->read('vendor/composer/installed.json'));
    }

    /**
     * @covers ::cleanupVendorInstalledJson
     * @covers ::updateNamespaces
     */
    public function test_updates_path_target_directory(): void
    {

        $installedJson = <<<'EOD'
{"packages":[{"name":"psr\/container","version":"1.1.2","version_normalized":"1.1.2.0","source":{"type":"git","url":"https:\/\/github.com\/php-fig\/container.git","reference":"513e0666f7216c7459170d56df27dfcefe1689ea"},"dist":{"type":"zip","url":"https:\/\/api.github.com\/repos\/php-fig\/container\/zipball\/513e0666f7216c7459170d56df27dfcefe1689ea","reference":"513e0666f7216c7459170d56df27dfcefe1689ea","shasum":""},"require":{"php":">=7.4.0"},"time":"2021-11-05T16:50:12+00:00","type":"library","installation-source":"dist","autoload":{"psr-4":{"Psr\\Container\\":"src\/"}},"notification-url":"https:\/\/packagist.org\/downloads\/","license":["MIT"],"authors":[{"name":"PHP-FIG","homepage":"https:\/\/www.php-fig.org\/"}],"description":"Common Container Interface (PHP FIG PSR-11)","homepage":"https:\/\/github.com\/php-fig\/container","keywords":["PSR-11","container","container-interface","container-interop","psr"],"support":{"issues":"https:\/\/github.com\/php-fig\/container\/issues","source":"https:\/\/github.com\/php-fig\/container\/tree\/1.1.2"},"install-path":"..\/psr\/container"}],"dev":true,"dev-package-names":[]}
EOD;

        $fileSystem = $this->getInMemoryFileSystem();

        $fileSystem->createDirectory('vendor/composer');
        $fileSystem->write('vendor/composer/installed.json', $installedJson);
        $fileSystem->write('vendor-prefixed/psr/container/src/ContainerInterface.php', '<?php namespace Psr\Container;');

        $config = Mockery::mock(CleanupConfigInterface::class);
        $config->expects('getAbsoluteVendorDirectory')->atLeast()->once()->andReturn('mem://vendor');
        $config->expects('getAbsoluteTargetDirectory')->atLeast()->once()->andReturn('mem://vendor-prefixed');
        $config->shouldReceive('getExcludePackagesFromCopy')->andReturn([]);
        $config->shouldReceive('isDryRun')->andReturnFalse();

        $sut = new InstalledJson(
            $config,
            $fileSystem,
            new NullLogger()
        );

        /** @var ComposerPackage|MockInterface $composerPackageMock */
        $composerPackageMock = Mockery::mock(ComposerPackage::class);
        $composerPackageMock->expects('didCopy')->once()->andReturnTrue();

        /** @var array<string,ComposerPackage> $flatDependencyTree*/
        $flatDependencyTree = ['psr/container'=> $composerPackageMock];

        $file = Mockery::mock(FileWithDependency::class);
        $file->expects('getSourcePath')->andReturn('vendor/psr/container/src/ContainerInterface.php');
        $file->expects('addDiscoveredSymbol');

        $namespaceSymbol = new NamespaceSymbol('Psr\\Container', $file);
        $namespaceSymbol->setReplacement('BrianHenryIE\\Tests\\Psr\\Container',);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $sut->copyInstalledJson();
        $sut->cleanTargetDirInstalledJson($flatDependencyTree, $discoveredSymbols);

        $this->assertStringContainsString('"BrianHenryIE\\\\Tests\\\\Psr\\\\Container\\\\": "src/"', $fileSystem->read('vendor-prefixed/composer/installed.json'));
        $this->assertStringNotContainsString('"Psr\\\\Container\\\\": "src/"', $fileSystem->read('vendor-prefixed/composer/installed.json'));
    }

    public function test_updates_psr0_entry(): void
    {
        $installedJson = <<<'EOD'
{
    "packages": [
        {
            "name": "psr/log",
            "version": "1.0.0",
            "version_normalized": "1.0.0.0",
            "source": {
                "type": "git",
                "url": "https://github.com/php-fig/log.git",
                "reference": "fe0936ee26643249e916849d48e3a51d5f5e278b"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/php-fig/log/zipball/fe0936ee26643249e916849d48e3a51d5f5e278b",
                "reference": "fe0936ee26643249e916849d48e3a51d5f5e278b",
                "shasum": ""
            },
            "time": "2012-12-21T11:40:51+00:00",
            "type": "library",
            "installation-source": "dist",
            "autoload": {
                "psr-0": {
                    "Psr\\Log\\": ""
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "PHP-FIG",
                    "homepage": "http://www.php-fig.org/"
                }
            ],
            "description": "Common interface for logging libraries",
            "keywords": [
                "log",
                "psr",
                "psr-3"
            ],
            "support": {
                "issues": "https://github.com/php-fig/log/issues",
                "source": "https://github.com/php-fig/log/tree/1.0.0"
            },
            "install-path": "../psr/log"
        }
    ],
    "dev": false,
    "dev-package-names": []
}
EOD;

        $fileSystem = $this->getInMemoryFileSystem();

        $fileSystem->createDirectory('vendor/composer');
        $fileSystem->write('vendor/composer/installed.json', $installedJson);
        $fileSystem->write('vendor-prefixed/psr/log/src/AbstractLogger.php', '<?php namespace Psr\Log;');

        $config = Mockery::mock(CleanupConfigInterface::class);
        $config->expects('getAbsoluteVendorDirectory')->atLeast()->once()->andReturn('mem://vendor');
        $config->expects('getAbsoluteTargetDirectory')->atLeast()->once()->andReturn('mem://vendor-prefixed');
        $config->shouldReceive('getExcludePackagesFromCopy')->andReturn([]);
        $config->shouldReceive('isDryRun')->andReturnFalse();

        $sut = new InstalledJson(
            $config,
            $fileSystem,
            new NullLogger()
        );

        /** @var ComposerPackage|MockInterface $composerPackageMock */
        $composerPackageMock = Mockery::mock(ComposerPackage::class);
        $composerPackageMock->expects('didCopy')->once()->andReturnTrue();

        /** @var array<string,ComposerPackage> $flatDependencyTree*/
        $flatDependencyTree = ['psr/log'=> $composerPackageMock];

        $file = Mockery::mock(FileWithDependency::class);
        $file->expects('getSourcePath')->andReturn('vendor/psr/log/src/AbstractLogger.php');
        $file->expects('addDiscoveredSymbol');

        $namespaceSymbol = new NamespaceSymbol('Psr\\Log', $file);
        $namespaceSymbol->setReplacement('BrianHenryIE\\Tests\\Psr\\Log',);

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add($namespaceSymbol);

        $sut->copyInstalledJson();
        $sut->cleanTargetDirInstalledJson($flatDependencyTree, $discoveredSymbols);

        $this->assertStringContainsString('"BrianHenryIE\\\\Tests\\\\Psr\\\\Log\\\\": ""', $fileSystem->read('vendor-prefixed/composer/installed.json'));
        $this->assertStringNotContainsString('"Psr\\\\Log\\\\": ""', $fileSystem->read('vendor-prefixed/composer/installed.json'));
    }

    /**
     * @covers ::copyInstalledJson
     * @covers ::cleanTargetDirInstalledJson
     * @covers ::cleanupVendorInstalledJson
     */
    public function test_excluded_package_removed_from_target_installed_json_but_retained_in_vendor_installed_json(): void
    {
        $installedJson = <<<'EOD'
{
    "packages": [
        {
            "name": "psr/log",
            "version": "1.1.4",
            "version_normalized": "1.1.4.0",
            "type": "library",
            "installation-source": "dist",
            "autoload": {
                "psr-4": {
                    "Psr\\Log\\": ""
                }
            },
            "install-path": "../psr/log"
        }
    ],
    "dev": false,
    "dev-package-names": []
}
EOD;

        $fileSystem = $this->getInMemoryFileSystem();
        $fileSystem->createDirectory('vendor/composer');
        $fileSystem->createDirectory('vendor/psr/log');
        $fileSystem->write('vendor/psr/log/LoggerInterface.php', '<?php');
        $fileSystem->write('vendor/composer/installed.json', $installedJson);

        $config = Mockery::mock(CleanupConfigInterface::class);
        $config->shouldReceive('getAbsoluteVendorDirectory')->andReturn('mem://vendor');
        $config->shouldReceive('getAbsoluteTargetDirectory')->andReturn('mem://vendor-prefixed');
        $config->shouldReceive('getExcludePackagesFromCopy')->andReturn(['psr/log']);
        $config->shouldReceive('isDryRun')->andReturnFalse();

        $sut = new InstalledJson(
            $config,
            $fileSystem,
            new NullLogger()
        );

        /** @var ComposerPackage|MockInterface $composerPackageMock */
        $composerPackageMock = Mockery::mock(ComposerPackage::class);
        $composerPackageMock->shouldReceive('didCopy')->andReturnFalse();
        $composerPackageMock->shouldReceive('didDelete')->andReturnFalse();

        /** @var array<string,ComposerPackage> $flatDependencyTree */
        $flatDependencyTree = ['psr/log' => $composerPackageMock];

        $discoveredSymbols = new DiscoveredSymbols();

        $sut->copyInstalledJson();
        $sut->cleanTargetDirInstalledJson($flatDependencyTree, $discoveredSymbols);
        $sut->cleanupVendorInstalledJson($flatDependencyTree, $discoveredSymbols);

        $vendorInstalledJson = $fileSystem->read('vendor/composer/installed.json');
        $vendorInstalledPackageNames = $this->extractPackageNamesFromInstalledJson($vendorInstalledJson);
        $this->assertContains('psr/log', $vendorInstalledPackageNames);

        $targetInstalledJson = $fileSystem->read('vendor-prefixed/composer/installed.json');
        $targetInstalledPackageNames = $this->extractPackageNamesFromInstalledJson($targetInstalledJson);
        $this->assertNotContains('psr/log', $targetInstalledPackageNames);
    }

    public function test_clean_target_installed_json_keeps_copied_packages_and_removes_excluded_and_dev_packages(): void
    {
        $fileSystem = $this->getInMemoryFileSystem();
        $fileSystem->createDirectory('vendor/composer');
        $fileSystem->createDirectory('vendor-prefixed/vendor/copied/src');
        $fileSystem->write('vendor/composer/installed.json', $this->installedJson([
            $this->installedPackage('vendor/copied', ['psr-4' => ['Vendor\\Copied\\' => 'src/']]),
            $this->installedPackage('vendor/excluded', ['psr-4' => ['Vendor\\Excluded\\' => 'src/']]),
            $this->installedPackage('vendor/dev-only', ['psr-4' => ['Vendor\\DevOnly\\' => 'src/']]),
        ], true, ['vendor/dev-only']));

        $config = $this->installedJsonConfig(excludePackagesFromCopy: ['vendor/excluded']);
        $sut = new InstalledJson($config, $fileSystem, new NullLogger());

        $flatDependencyTree = [
            'vendor/copied' => $this->composerPackage(didCopy: true),
            'vendor/excluded' => $this->composerPackage(didCopy: false),
        ];

        $sut->copyInstalledJson();
        $sut->cleanTargetDirInstalledJson($flatDependencyTree, new DiscoveredSymbols());

        $targetInstalledJson = $this->readInstalledJsonArray($fileSystem, 'vendor-prefixed/composer/installed.json');
        self::assertSame(['vendor/copied'], $this->packageNames($targetInstalledJson));
        self::assertFalse($targetInstalledJson['dev']);
        self::assertSame([], $targetInstalledJson['dev-package-names']);
    }

    public function test_cleanup_vendor_installed_json_filters_missing_autoload_paths_for_all_supported_autoload_types(): void
    {
        $fileSystem = $this->getInMemoryFileSystem();
        $fileSystem->createDirectory('vendor/composer');
        $fileSystem->write('vendor/vendor/pkg/src/keep.php', '<?php');
        $fileSystem->createDirectory('vendor/vendor/pkg/classes');
        $fileSystem->createDirectory('vendor/vendor/pkg/legacy');
        $fileSystem->write('vendor/composer/installed.json', $this->installedJson([
            $this->installedPackage('vendor/pkg', [
                'files' => ['src/keep.php', 'src/missing.php'],
                'classmap' => ['classes', 'missing-classmap'],
                'psr-4' => [
                    'Vendor\\Pkg\\' => 'src/',
                    'Vendor\\Pkg\\Extra\\' => ['src/', 'missing-dir'],
                ],
                'psr-0' => [
                    'Legacy_' => ['legacy', 'missing-legacy'],
                ],
                'exclude-from-classmap' => ['/Tests/'],
            ]),
        ]));

        $sut = new InstalledJson($this->installedJsonConfig(), $fileSystem, new NullLogger());
        $sut->cleanupVendorInstalledJson(
            ['vendor/pkg' => $this->composerPackage(didDelete: false)],
            new DiscoveredSymbols()
        );

        $installedJson = $this->readInstalledJsonArray($fileSystem, 'vendor/composer/installed.json');
        $autoload = $installedJson['packages'][0]['autoload'];
        self::assertSame(['src/keep.php'], $autoload['files']);
        self::assertSame(['classes'], $autoload['classmap']);
        self::assertSame('src/', $autoload['psr-4']['Vendor\\Pkg\\']);
        self::assertSame(['src/'], $autoload['psr-4']['Vendor\\Pkg\\Extra\\']);
        self::assertSame(['legacy'], $autoload['psr-0']['Legacy_']);
        self::assertSame(['/Tests/'], $autoload['exclude-from-classmap']);
    }

    public function test_cleanup_vendor_installed_json_clears_deleted_package_autoload_when_package_record_remains(): void
    {
        $fileSystem = $this->getInMemoryFileSystem();
        $fileSystem->createDirectory('vendor/composer');
        $fileSystem->createDirectory('vendor/vendor/pkg/src');
        $fileSystem->write('vendor/composer/installed.json', $this->installedJson([
            $this->installedPackage('vendor/pkg', ['psr-4' => ['Vendor\\Pkg\\' => 'src/']]),
        ]));

        $sut = new InstalledJson($this->installedJsonConfig(), $fileSystem, new NullLogger());
        $sut->cleanupVendorInstalledJson(
            ['vendor/pkg' => $this->composerPackage(didDelete: true)],
            new DiscoveredSymbols()
        );

        $installedJson = $this->readInstalledJsonArray($fileSystem, 'vendor/composer/installed.json');
        self::assertSame(['vendor/pkg'], $this->packageNames($installedJson));
        self::assertSame([], $installedJson['packages'][0]['autoload']);
    }

    /**
     * @return string[]
     */
    private function extractPackageNamesFromInstalledJson(string $installedJson): array
    {
        $installedJsonArray = json_decode($installedJson, true);

        $this->assertIsArray($installedJsonArray, 'installed.json should decode to an array');
        $this->assertArrayHasKey('packages', $installedJsonArray, 'installed.json should contain packages');
        $this->assertIsArray($installedJsonArray['packages']);

        return array_values(array_filter(array_map(
            static fn(array $package): ?string => $package['name'] ?? null,
            $installedJsonArray['packages']
        )));
    }

    /**
     * @param array<int,array<string,mixed>> $packages
     * @param string[] $devPackageNames
     */
    private function installedJson(array $packages, bool $dev = false, array $devPackageNames = []): string
    {
        return json_encode([
            'packages' => $packages,
            'dev' => $dev,
            'dev-package-names' => $devPackageNames,
        ], JSON_PRETTY_PRINT);
    }

    /**
     * @param array<string,mixed> $autoload
     * @return array<string,mixed>
     */
    private function installedPackage(string $name, array $autoload, ?string $installPath = null): array
    {
        return [
            'name' => $name,
            'version' => '1.0.0',
            'version_normalized' => '1.0.0.0',
            'type' => 'library',
            'installation-source' => 'dist',
            'autoload' => $autoload,
            'install-path' => $installPath ?? '../' . $name,
        ];
    }

    private function installedJsonConfig(array $excludePackagesFromCopy = []): CleanupConfigInterface
    {
        $config = Mockery::mock(CleanupConfigInterface::class);
        $config->shouldReceive('getAbsoluteVendorDirectory')->andReturn('mem://vendor');
        $config->shouldReceive('getAbsoluteTargetDirectory')->andReturn('mem://vendor-prefixed');
        $config->shouldReceive('getExcludePackagesFromCopy')->andReturn($excludePackagesFromCopy);
        $config->shouldReceive('isDryRun')->andReturnFalse();

        return $config;
    }

    private function composerPackage(bool $didCopy = false, bool $didDelete = false): ComposerPackage
    {
        /** @var ComposerPackage|MockInterface $composerPackage */
        $composerPackage = Mockery::mock(ComposerPackage::class);
        $composerPackage->shouldReceive('didCopy')->andReturn($didCopy);
        $composerPackage->shouldReceive('didDelete')->andReturn($didDelete);

        return $composerPackage;
    }

    /**
     * @return array{packages:array<int,array<string,mixed>>, dev:bool, dev-package-names:array<string>}
     */
    private function readInstalledJsonArray(FileSystem $fileSystem, string $path): array
    {
        $installedJson = json_decode($fileSystem->read($path), true);
        self::assertIsArray($installedJson);

        return $installedJson;
    }

    /**
     * @param array{packages:array<int,array<string,mixed>>} $installedJson
     * @return string[]
     */
    private function packageNames(array $installedJson): array
    {
        return array_values(array_map(
            static fn(array $package): string => $package['name'],
            $installedJson['packages']
        ));
    }
}
