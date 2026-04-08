<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\CopierConfigInterface;
use BrianHenryIE\Strauss\Config\FileCopyScannerConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\TestCase;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\FileCopyScanner
 */
class FileCopyScannerTest extends TestCase
{
    /**
     * @covers ::__construct
     * @covers ::isFilePathExcluded
     */
    public function test_file_is_excluded(): void
    {
        $vendorRelativePath = 'my/package/file.php';
        $regexPattern = "~^([^/]*?/){2}file.php~";

        $dependency = Mockery::mock(ComposerPackage::class);
        $dependency->expects('getPackageAbsolutePath')->andReturn('/path/to/project/vendor/my/package');
        $dependency->expects('addFile');
        $dependency->expects('getPackageName')->andReturn('my/package');
//        $dependency->expects('getRelativePath')->andReturn('my/package');

        $file = new FileWithDependency(
            $dependency,
            $vendorRelativePath,
            '/path/to/project/vendor/my/package/file.php'
        );

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $config = \Mockery::mock(FileCopyScannerConfigInterface::class);
        $config->expects('getTargetDirectory')->atLeast()->once()->andReturns('vendor-prefixed');
        $config->expects('getVendorDirectory')->atLeast()->once()->andReturns('vendor');
        $config->expects('getExcludePackagesFromCopy')->andReturns([]);
        $config->expects('isDeleteVendorFiles')->andReturnFalse();
        $config->expects('getExcludeFilePatternsFromCopy')->andReturns([$regexPattern]);

        $filesystem = $this->getInMemoryFileSystem();

        $sut = new FileCopyScanner($config, $filesystem, $this->getLogger());
        $sut->scanFiles($discoveredFiles);

        $this->assertFalse($file->isDoCopy());
    }
}
