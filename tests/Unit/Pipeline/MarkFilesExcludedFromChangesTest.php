<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\DependenciesCollection;
use BrianHenryIE\Strauss\Config\MarkFilesExcludedFromChangesConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileCodeMap;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\MarkFilesExcludedFromChanges
 */
class MarkFilesExcludedFromChangesTest extends TestCase
{
    /**
     * Helper to build a config mock. Note: the source calls `getExcludeFilePatternsFromPrefixing()`
     * rather than the interface-defined `getExcludeFilesFromUpdateFilePatterns()`.
     *
     * @param string[] $excludeFilePatterns
     * @param string[] $excludeNamespaces
     * @param string[] $excludePackages
     * @param ?DependenciesCollection $allPackages The dependency tree; null when not known.
     */
    private function createConfigMock(
        array $excludeFilePatterns = [],
        array $excludeNamespaces = [],
        array $excludePackages = [],
        ?DependenciesCollection $allPackages = null
    ): MarkFilesExcludedFromChangesConfigInterface {
        $config = Mockery::mock(MarkFilesExcludedFromChangesConfigInterface::class);
        $config->shouldReceive('getAllPackages')->andReturn($allPackages);
        $config->shouldReceive('getExcludeFilesFromUpdateFilePatterns')->andReturn($excludeFilePatterns);
        $config->shouldReceive('getExcludeFileFromUpdateNamespaces')->andReturn($excludeNamespaces);
        $config->shouldReceive('getExcludeFilesFromUpdatePackages')->andReturn($excludePackages);
        return $config;
    }

    /**
     * A file whose vendor-relative path matches a configured pattern should have setDoUpdate(false) called.
     *
     * @covers ::scanDiscoveredFiles
     * @covers ::fileMatchesFilePattern
     * @covers ::preparePattern
     */
    public function testFileMatchingPatternIsExcludedFromChanges(): void
    {
        $config = $this->createConfigMock(['#psr/log#']);
        $sut = new MarkFilesExcludedFromChanges($config, $this->getLogger());

        $file = new File(
            '/vendor/psr/log/src/LoggerInterface.php',
            'psr/log/src/LoggerInterface.php',
            '/vendor-prefixed/psr/log/src/LoggerInterface.php'
        );

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $sut->scanDiscoveredFiles($discoveredFiles);

        $this->assertFalse($file->getDoUpdate());
    }

    /**
     * A file whose path does not match any pattern should not have setDoUpdate called.
     *
     * @covers ::scanDiscoveredFiles
     * @covers ::fileMatchesFilePattern
     */
    public function testFileNotMatchingAnyPatternIsNotExcluded(): void
    {
        $config = $this->createConfigMock(['#psr/log#']);
        $sut = new MarkFilesExcludedFromChanges($config, $this->getLogger());

        $file = new File(
            '/vendor/monolog/monolog/src/Logger.php',
            'monolog/monolog/src/Logger.php',
            '/vendor-prefixed/monolog/monolog/src/Logger.php'
        );

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $sut->scanDiscoveredFiles($discoveredFiles);

        $this->assertTrue($file->getDoUpdate());
    }

    /**
     * A pattern string without delimiter characters should be wrapped with '#' delimiters and still match.
     *
     * @covers ::scanDiscoveredFiles
     * @covers ::fileMatchesFilePattern
     * @covers ::preparePattern
     */
    public function testFilePatternWithoutDelimiterIsWrappedAndMatches(): void
    {
        $config = $this->createConfigMock(['psr/log']);
        $sut = new MarkFilesExcludedFromChanges($config, $this->getLogger());

        $file = new File(
            '/vendor/psr/log/src/LoggerInterface.php',
            'psr/log/src/LoggerInterface.php',
            '/vendor-prefixed/psr/log/src/LoggerInterface.php'
        );

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $sut->scanDiscoveredFiles($discoveredFiles);

        $this->assertFalse($file->getDoUpdate());
    }

    /**
     * A pattern whose first and last characters match (a valid delimiter) should be used as-is.
     *
     * @covers ::preparePattern
     * @covers ::fileMatchesFilePattern
     */
    public function testFilePatternWithMatchingDelimitersIsNotRewrapped(): void
    {
        $config = $this->createConfigMock(['/psr\/log/']);
        $sut = new MarkFilesExcludedFromChanges($config, $this->getLogger());

        $file = new File(
            '/vendor/psr/log/src/LoggerInterface.php',
            'psr/log/src/LoggerInterface.php',
            '/vendor-prefixed/psr/log/src/LoggerInterface.php'
        );

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $sut->scanDiscoveredFiles($discoveredFiles);

        $this->assertFalse($file->getDoUpdate());
    }

    /**
     * A FileWithDependency whose package name is in the excluded packages list should be excluded.
     *
     * @covers ::scanDiscoveredFiles
     * @covers ::fileMatchesPackage
     */
    public function testFileWithDependencyMatchingExcludedPackageIsExcluded(): void
    {
        $config = $this->createConfigMock([], [], ['psr/log']);

        $package = Mockery::mock(ComposerPackage::class);
        $package->shouldReceive('getPackageName')->andReturn('psr/log');
        $package->shouldReceive('getPackageAbsolutePath')->andReturn('/vendor/psr/log');
        $package->shouldReceive('addFile');

        $sut = new MarkFilesExcludedFromChanges($config, $this->getLogger());

        $file = new FileWithDependency(
            $package,
            'psr/log/src/LoggerInterface.php',
            '/vendor/psr/log/src/LoggerInterface.php',
            '/vendor-prefixed/psr/log/src/LoggerInterface.php'
        );

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $sut->scanDiscoveredFiles($discoveredFiles);

        $this->assertNotTrue($file->getDoUpdate());
    }

    /**
     * A FileWithDependency from a package not in the excluded list should not be excluded.
     *
     * @covers ::scanDiscoveredFiles
     * @covers ::fileMatchesPackage
     */
    public function testFileWithDependencyNotMatchingExcludedPackageIsNotExcluded(): void
    {
        $config = $this->createConfigMock([], [], ['psr/log']);

        $package = Mockery::mock(ComposerPackage::class);
        $package->shouldReceive('getPackageName')->andReturn('monolog/monolog');
        $package->shouldReceive('getPackageAbsolutePath')->andReturn('/vendor/monolog/monolog');
        $package->shouldReceive('addFile');

        $sut = new MarkFilesExcludedFromChanges($config, $this->getLogger());

        $file = new FileWithDependency(
            $package,
            'monolog/monolog/src/Logger.php',
            '/vendor/monolog/monolog/src/Logger.php',
            '/vendor-prefixed/monolog/monolog/src/Logger.php'
        );

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $sut->scanDiscoveredFiles($discoveredFiles);

        $this->assertTrue($file->getDoUpdate());
    }

    /**
     * A plain File (not FileWithDependency) is never excluded by package rules.
     *
     * @covers ::scanDiscoveredFiles
     * @covers ::fileMatchesPackage
     */
    public function testPlainFileIsNeverExcludedByPackageRule(): void
    {
        $config = $this->createConfigMock([], [], ['psr/log']);
        $sut = new MarkFilesExcludedFromChanges($config, $this->getLogger());

        $file = new File(
            '/src/MyClass.php',
            'src/MyClass.php',
            '/vendor-prefixed/src/MyClass.php'
        );

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $sut->scanDiscoveredFiles($discoveredFiles);

        $this->assertTrue($file->getDoUpdate());
    }

    /**
     * With no exclusion config, no files are excluded.
     *
     * @covers ::scanDiscoveredFiles
     */
    public function testNoExclusionRulesConfiguredMeansNoFilesExcluded(): void
    {
        $config = $this->createConfigMock();
        $sut = new MarkFilesExcludedFromChanges($config, $this->getLogger());

        $file = new File(
            '/vendor/somepackage/src/SomeClass.php',
            'somepackage/src/SomeClass.php',
            '/vendor-prefixed/somepackage/src/SomeClass.php'
        );

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $sut->scanDiscoveredFiles($discoveredFiles);

        $this->assertTrue($file->getDoUpdate());
    }

    /**
     * A file containing a namespace that matches an entry in the excluded namespaces list is excluded.
     *
     * @covers ::scanDiscoveredFiles
     * @covers ::fileMatchesNamespace
     */
    public function testFileWithExcludedNamespaceIsExcludedFromChanges(): void
    {
        $config = $this->createConfigMock([], ['Psr\\Log']);
        $sut = new MarkFilesExcludedFromChanges($config, $this->getLogger());

        $file = new File(
            '/vendor/psr/log/src/LoggerInterface.php',
            'psr/log/src/LoggerInterface.php',
            '/vendor-prefixed/psr/log/src/LoggerInterface.php'
        );
        $file->addDiscoveredSymbol(new NamespaceSymbol('Psr\\Log', $file));

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $sut->scanDiscoveredFiles($discoveredFiles);

        $this->assertNotTrue($file->getDoUpdate());
    }

    /**
     * A file containing only a namespace not in the excluded list should not be excluded.
     *
     * @covers ::scanDiscoveredFiles
     * @covers ::fileMatchesNamespace
     */
    public function testFileWithNonExcludedNamespaceIsNotExcluded(): void
    {
        $config = $this->createConfigMock([], ['Psr\\Log']);
        $sut = new MarkFilesExcludedFromChanges($config, $this->getLogger());

        $file = new File(
            '/vendor/monolog/monolog/src/Logger.php',
            'monolog/monolog/src/Logger.php',
            '/vendor-prefixed/monolog/monolog/src/Logger.php'
        );
        $file->addDiscoveredSymbol(new NamespaceSymbol('Monolog', $file));

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $sut->scanDiscoveredFiles($discoveredFiles);

        $this->assertTrue($file->getDoUpdate());
    }

    /**
     * Among multiple files, only those matching a rule are excluded.
     *
     * @covers ::scanDiscoveredFiles
     */
    public function testOnlyMatchingFilesAreExcludedAmongMultiple(): void
    {
        $config = $this->createConfigMock(['#psr/log#']);
        $sut = new MarkFilesExcludedFromChanges($config, $this->getLogger());

        $excludedFile = new File(
            '/vendor/psr/log/src/LoggerInterface.php',
            'psr/log/src/LoggerInterface.php',
            '/vendor-prefixed/psr/log/src/LoggerInterface.php'
        );
        $nonExcludedFile = new File(
            '/vendor/monolog/monolog/src/Logger.php',
            'monolog/monolog/src/Logger.php',
            '/vendor-prefixed/monolog/monolog/src/Logger.php'
        );

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($excludedFile);
        $discoveredFiles->add($nonExcludedFile);

        $sut->scanDiscoveredFiles($discoveredFiles);

        $this->assertFalse($excludedFile->getDoUpdate(), 'Matching file should be excluded');
        $this->assertTrue($nonExcludedFile->getDoUpdate(), 'Non-matching file should not be excluded');
    }

    /**
     * @return ComposerPackage&\Mockery\MockInterface
     */
    private function createPackageMock(string $packageName): ComposerPackage
    {
        $package = Mockery::mock(ComposerPackage::class);
        $package->shouldReceive('getPackageName')->andReturn($packageName);
        $package->shouldReceive('getPackageAbsolutePath')->andReturn('/project/vendor/' . $packageName);
        $package->shouldReceive('addFile');

        return $package;
    }

    private function createPackageFile(ComposerPackage $package, string $packageRelativePath): FileWithDependency
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
     * When prefixing in place, the files of packages outside the dependency tree which were not parsed, because they
     * mention none of the symbols found or are not PHP, are not changed.
     *
     * @covers ::scanDiscoveredFiles
     * @covers ::isUnparsedFileOutsideDependencyTree
     */
    public function testUnparsedFileOutsideDependencyTreeIsExcludedFromChanges(): void
    {
        $psrLog = $this->createPackageMock('psr/log');
        $monolog = $this->createPackageMock('monolog/monolog');

        $sut = new MarkFilesExcludedFromChanges(
            $this->createConfigMock([], [], [], new DependenciesCollection([$psrLog])),
            $this->getLogger()
        );

        $unparsedFile = $this->createPackageFile($monolog, 'src/Unrelated.php');
        $nonPhpFile = $this->createPackageFile($monolog, 'views/error.blade.txt');

        $parsedFile = $this->createPackageFile($monolog, 'src/Logger.php');
        $parsedFile->setCodeMap(new FileCodeMap([], '<?php namespace Monolog; use Psr\Log\LoggerInterface;'));

        // E.g. a template in a package in the tree.
        $treeFile = $this->createPackageFile($psrLog, 'src/template.txt');

        $discoveredFiles = new DiscoveredFiles([$unparsedFile, $nonPhpFile, $parsedFile, $treeFile]);

        $sut->scanDiscoveredFiles($discoveredFiles);

        $this->assertFalse($unparsedFile->getDoUpdate());
        $this->assertFalse($unparsedFile->isDoPrefix());
        $this->assertFalse($nonPhpFile->getDoUpdate());

        $this->assertTrue($parsedFile->getDoUpdate());
        $this->assertTrue($parsedFile->isDoPrefix());
        $this->assertTrue($treeFile->getDoUpdate());
    }

    /**
     * When the dependency tree is not known, e.g. the `replace` command, no file is excluded for it.
     *
     * @covers ::scanDiscoveredFiles
     * @covers ::isUnparsedFileOutsideDependencyTree
     */
    public function testNoFileIsExcludedWhenTheDependencyTreeIsNotKnown(): void
    {
        $sut = new MarkFilesExcludedFromChanges($this->createConfigMock(), $this->getLogger());

        $file = $this->createPackageFile($this->createPackageMock('monolog/monolog'), 'src/Unrelated.php');

        $sut->scanDiscoveredFiles(new DiscoveredFiles([$file]));

        $this->assertTrue($file->getDoUpdate());
        $this->assertTrue($file->isDoPrefix());
    }
}
