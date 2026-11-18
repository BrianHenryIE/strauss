<?php

/**
 * When prefixing in place, packages outside the dependency tree only matter for their uses of the symbols found, so
 * only their files which mention one are parsed.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/343
 * @see https://github.com/BrianHenryIE/strauss/issues/173
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\DependenciesCollection;
use BrianHenryIE\Strauss\Config\FileSymbolScannerConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use Mockery;
use Mockery\MockInterface;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\FileSymbolScanner
 */
class FileSymbolScannerPackagesOutsideTreeTest extends TestCase
{
    protected const FILE_CONTENTS = [
        '/project/vendor/psr/log/src/LoggerInterface.php' => '<?php namespace Psr\Log; interface LoggerInterface {}',
        '/project/vendor/monolog/monolog/src/Logger.php' => '<?php namespace Monolog; use Psr\Log\LoggerInterface; class Logger implements LoggerInterface {}',
        '/project/vendor/monolog/monolog/src/Unrelated.php' => '<?php namespace Monolog; class Unrelated {}',
        '/project/vendor/monolog/monolog/views/error.blade.txt' => '@extends("errors::minimal")',
    ];

    /**
     * @return ComposerPackage&MockInterface
     */
    protected function getPackage(string $packageName): ComposerPackage
    {
        $package = Mockery::mock(ComposerPackage::class);
        $package->shouldReceive('getPackageName')->andReturn($packageName);
        $package->shouldReceive('getPackageAbsolutePath')->andReturn('/project/vendor/' . $packageName);
        $package->shouldReceive('addFile');
        $package->shouldReceive('addDiscoveredSymbol');
        $package->shouldReceive('isPsr0Autoloaded')->andReturnFalse();
        $package->shouldReceive('__toString')->andReturn($packageName);

        return $package;
    }

    protected function getFile(ComposerPackage $package, string $packageRelativePath): FileWithDependency
    {
        $vendorRelativePath = $package->getPackageName() . '/' . $packageRelativePath;

        return new FileWithDependency(
            $package,
            $vendorRelativePath,
            '/project/vendor/' . $vendorRelativePath,
            '/project/vendor/' . $vendorRelativePath
        );
    }

    /**
     * @return array{0:DiscoveredSymbols, 1:array<string,FileWithDependency>, 2:string[]}
     *                                    The symbols found, the files by name, and the paths which were read.
     */
    protected function scan(bool $isTreeKnown): array
    {
        $psrLog = $this->getPackage('psr/log');
        $monolog = $this->getPackage('monolog/monolog');

        // psr/log is in the dependency tree; monolog/monolog is not in it.
        $config = Mockery::mock(FileSymbolScannerConfigInterface::class);
        $config->shouldReceive('getAllPackages')->andReturn($isTreeKnown ? new DependenciesCollection([$psrLog]) : null);
        $config->shouldReceive('getProjectAbsolutePath')->andReturn('/project');

        $pathsRead = [];
        $filesystem = Mockery::mock(FileSystem::class);
        $filesystem->shouldReceive('read')->andReturnUsing(
            function (string $path) use (&$pathsRead): string {
                $pathsRead[] = $path;
                return self::FILE_CONTENTS[$path];
            }
        );

        // The package outside the tree first: its files can only be filtered once psr/log's symbols are found.
        $files = [
            'Logger' => $this->getFile($monolog, 'src/Logger.php'),
            'Unrelated' => $this->getFile($monolog, 'src/Unrelated.php'),
            'blade' => $this->getFile($monolog, 'views/error.blade.txt'),
            'LoggerInterface' => $this->getFile($psrLog, 'src/LoggerInterface.php'),
        ];

        $discoveredFiles = new DiscoveredFiles();
        foreach ($files as $file) {
            $discoveredFiles->add($file);
        }

        $sut = new FileSymbolScanner($config, new DiscoveredSymbols(), $filesystem, $this->getLogger());

        return [$sut->findInFiles($discoveredFiles), $files, $pathsRead];
    }

    /**
     * @covers ::findInFiles
     * @covers ::scanFiles
     */
    public function test_only_files_mentioning_the_symbols_found_are_parsed(): void
    {
        [$discoveredSymbols, $files, $pathsRead] = $this->scan(true);

        self::assertNotNull($discoveredSymbols->getInterface('Psr\Log\LoggerInterface'));

        // Mentions `Psr`.
        self::assertNotNull($discoveredSymbols->getClass('Monolog\Logger'));
        self::assertNotNull($files['Logger']->getCodeMap());

        // Read to check for mentions, but not parsed, so {@see MarkFilesExcludedFromChanges} leaves it unchanged.
        self::assertNull($discoveredSymbols->getClass('Monolog\Unrelated'));
        self::assertContains('/project/vendor/monolog/monolog/src/Unrelated.php', $pathsRead);
        self::assertNull($files['Unrelated']->getCodeMap());

        // Not PHP: not even read.
        self::assertNotContains('/project/vendor/monolog/monolog/views/error.blade.txt', $pathsRead);
        self::assertNull($files['blade']->getCodeMap());

        // Whether files are changed, or their symbols renamed, is decided later.
        foreach ($files as $file) {
            self::assertTrue($file->getDoUpdate());
            self::assertTrue($file->isDoPrefix());
        }

        // psr/log was scanned before monolog: the files outside the tree are only read afterwards.
        self::assertSame('/project/vendor/psr/log/src/LoggerInterface.php', $pathsRead[0]);

        self::assertTrue($this->getTestLogger()->hasInfoThatContains('Scanning 1 of 3 files'));
    }

    /**
     * When the dependency tree is not known, e.g. the `replace` command, every file is scanned.
     *
     * @covers ::findInFiles
     */
    public function test_every_file_is_scanned_when_the_tree_is_not_known(): void
    {
        [$discoveredSymbols, $files] = $this->scan(false);

        self::assertNotNull($discoveredSymbols->getClass('Monolog\Logger'));
        self::assertNotNull($discoveredSymbols->getClass('Monolog\Unrelated'));
        self::assertNotNull($files['Unrelated']->getCodeMap());
    }
}
