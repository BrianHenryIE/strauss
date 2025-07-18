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
        $dependency = Mockery::mock(ComposerPackage::class)->makePartial();
        $dependency->expects('isDoDelete')->once()->andReturnTrue();

        $sut = new FileWithDependency(
            $dependency,
            'company/package/src/path/file.php',
            '/absolute/path/to/project/vendor/company/package/src/path/file.php'
        );

        // Should defer to the package's `isDelete` setting.
        $this->assertTrue($sut->isDoDelete());

        $sut->setDoDelete(false);

        // Should use its specific setting.
        $this->assertFalse($sut->isDoDelete());
    }
}
