<?php

namespace BrianHenryIE\Strauss\Pipeline\Cleanup;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\CleanupConfigInterface;
use BrianHenryIE\Strauss\Files\FileWithDependency;
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
        $config->expects()->getVendorDirectory()->once()->andReturn('vendor/');

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
        $config->expects('getVendorDirectory')->atLeast()->once()->andReturn('mem://vendor/');
//        $config->expects('getTargetDirectory')->times(1)->andReturn('mem://vendor-prefixed/');

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
        $config->expects('getVendorDirectory')->atLeast()->once()->andReturn('mem://vendor/');
        $config->expects('getTargetDirectory')->atLeast()->once()->andReturn('mem://vendor-prefixed/');

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
        $config->expects('getVendorDirectory')->atLeast()->once()->andReturn('mem://vendor/');
        $config->expects('getTargetDirectory')->atLeast()->once()->andReturn('mem://vendor-prefixed/');

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
}
