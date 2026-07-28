<?php

namespace BrianHenryIE\Strauss\Files;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\TestCase;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Files\FileWithDependency
 */
class FileWithDependencyTest extends TestCase
{

    /**
     * @covers ::isDoDelete
     * @covers ::setDoDelete
     */
    public function test_is_do_delete(): void
    {
        /**
         * PHP 8.6: "Returning a value from a constructor is deprecated".
         * But it doesn't look like there is a value being returned.
         *
         * @see ComposerPackage
         * @see Mockery\Loader\EvalLoader
         */
        set_error_handler(function (int $errNo, string $errstr, string $errFile, int $errLine): bool {
            return true;
        }, E_DEPRECATED | E_USER_DEPRECATED);
        $dependency = Mockery::mock(ComposerPackage::class)->makePartial();
        restore_error_handler();
        $dependency->expects('isDoDelete')->once()->andReturnTrue();
        $dependency->allows('getPackageAbsolutePath')->andReturn('absolute/path/to/project/vendor/company/package');

        $sut = new FileWithDependency(
            $dependency,
            'company/package/src/path/file.php',
            'absolute/path/to/project/vendor/company/package/src/path/file.php'
        );

        // Should defer to the package's `isDelete` setting.
        $this->assertTrue($sut->isDoDelete());

        $sut->setDoDelete(false);

        // Should use its specific setting.
        $this->assertFalse($sut->isDoDelete());
    }

    /**
     * Verifies that FileWithDependency handles null packageAbsolutePath gracefully.
     * This preserves original str_replace() behavior where null is treated as empty string.
     *
     * @covers ::__construct
     * @covers ::getPackageRelativePath
     */
    public function test_handles_null_package_absolute_path(): void
    {
        $dependency = Mockery::mock(ComposerPackage::class)->makePartial();
        $dependency->allows('getPackageAbsolutePath')->andReturnNull();

        $sourceAbsolutePath = 'absolute/path/to/project/vendor/company/package/src/file.php';

        $sut = new FileWithDependency(
            $dependency,
            'company/package/src/file.php',
            $sourceAbsolutePath
        );

        // When packageAbsolutePath is null, nothing is replaced, so packageRelativePath equals sourceAbsolutePath
        $this->assertSame($sourceAbsolutePath, $sut->getPackageRelativePath());
    }
}
