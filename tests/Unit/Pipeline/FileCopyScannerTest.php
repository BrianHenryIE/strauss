<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\FileCopyScannerConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
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

	    /**
	     * PHP 8.6: "Returning a value from a constructor is deprecated".
	     * But it doesn't look like there is a value being returned.
	     *
	     * @see Prefixer
	     * @see FileEnumerator
	     * @see Mockery\Loader\EvalLoader
	     */
	    set_error_handler(function (int $errNo, string $errstr, string $errFile, int $errLine): bool {
		    return true;
	    }, E_DEPRECATED | E_USER_DEPRECATED);
        $dependency = Mockery::mock(ComposerPackage::class);
		restore_error_handler();

        $dependency->expects('getPackageAbsolutePath')->andReturn('path/to/project/vendor/my/package');
        $dependency->expects('addFile');
        $dependency->expects('getPackageName')->andReturn('my/package');
//        $dependency->expects('getRelativePath')->andReturn('my/package');

        $file = new FileWithDependency(
            $dependency,
            $vendorRelativePath,
            'path/to/project/vendor/my/package/file.php',
            'path/to/project/vendor-prefixed/my/package/file.php'
        );

        $discoveredFiles = new DiscoveredFiles();
        $discoveredFiles->add($file);

        $config = \Mockery::mock(FileCopyScannerConfigInterface::class);
        $config->expects('isTargetDirectoryVendor')->atLeast()->once()->andReturnFalse();
        $config->expects('getExcludePackagesFromCopy')->andReturns([]);
        $config->expects('isDeleteVendorFiles')->andReturnFalse();
        $config->expects('getExcludeNamespacesFromCopy')->andReturns([]);
        $config->expects('getExcludeFilePatternsFromCopy')->andReturns([$regexPattern]);

        $filesystem = $this->getFileSystem();

        $sut = new FileCopyScanner($config, $filesystem, $this->getLogger());
        $sut->scanFiles($discoveredFiles);

        $this->assertFalse($file->isDoCopy());
    }
}
