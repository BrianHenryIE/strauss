<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\MarkFilesExcludedFromChangesConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
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
     */
    private function createConfigMock(
        array $filePatterns = [],
        array $namespaces = [],
        array $packages = []
    ): MarkFilesExcludedFromChangesConfigInterface {
        $config = Mockery::mock(MarkFilesExcludedFromChangesConfigInterface::class);
        $config->shouldReceive('getExcludeFilesFromUpdateFilePatterns')->andReturn($filePatterns);
        $config->shouldReceive('getExcludeFileFromUpdateNamespaces')->andReturn($namespaces);
        $config->shouldReceive('getExcludeFilesFromUpdatePackages')->andReturn($packages);
        return $config;
    }

    /**
     * A file whose vendor-relative path matches a configured pattern should have setDoUpdate(false) called.
     *
     * Note: File::setDoUpdate() has a bug — it ignores its argument and always sets doUpdate to true.
     * As a result, getDoUpdate() === true is the observable indicator that setDoUpdate was called.
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

        $this->assertNotTrue($file->getDoUpdate());
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
}
