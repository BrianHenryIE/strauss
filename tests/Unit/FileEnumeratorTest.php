<?php

// Verify there are no // double slashes in paths.

// exclude_from_classmap

// exclude regex

// paths outside project directory

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\FileEnumeratorConfig;
use BrianHenryIE\Strauss\Pipeline\FileEnumerator;
use Mockery;

/**
 * Class FileEnumeratorTest
 * @package BrianHenryIE\Strauss
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\FileEnumerator
 */
class FileEnumeratorTest extends TestCase
{
    /**
     * @covers ::addFileWithDependency
     */
    public function test_file_does_not_exist()
    {
        $config = Mockery::mock(FileEnumeratorConfig::class);
        $filesystem = $this->getInMemoryFileSystem();
        $logger = $this->getLogger();

        $sut = new FileEnumerator($config, $filesystem, $logger);

        $dependency = Mockery::mock(ComposerPackage::class);
        $dependency->expects('getPackageName')->andReturn('test/package');
        $dependency->expects('getAutoload')->andReturn(['classmap' => ['src']]);
        $dependency->expects('getPackageAbsolutePath')->andReturn('/path/to/project/vendor/package');

        /** @var ComposerPackage[] $dependencies */
        $dependencies = [$dependency];

        $result = $sut->compileFileListForDependencies($dependencies);

        $this->assertEmpty($result->getFiles());

        $this->assertTrue($this->getTestLogger()->hasWarningRecords());
    }
}
