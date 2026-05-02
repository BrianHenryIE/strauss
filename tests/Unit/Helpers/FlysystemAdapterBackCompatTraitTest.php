<?php
/**
 *
 *
 * `composer require league/flysystem:"^3" --with-all-dependencies` (requires PHP 8)
 * `composer require league/flysystem:"^2" --with-all-dependencies` (requires PHP 8)
 * `composer require league/flysystem:"^2 || ^3"
 */

namespace BrianHenryIE\Strauss\Helpers;

use Composer\InstalledVersions;
use League\Flysystem\Config;
use League\Flysystem\PathNormalizer;
use League\Flysystem\Visibility;
use League\Flysystem\WhitespacePathNormalizer;
use League\MimeTypeDetection\MimeTypeDetector;

/**
 * @covers \BrianHenryIE\Strauss\Helpers\FlysystemAdapterBackCompatTrait
 */
class FlysystemAdapterBackCompatTraitTest extends \BrianHenryIE\Strauss\TestCase
{
    protected string $flysystemVersion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flysystemVersion = InstalledVersions::getVersion('league/flysystem');
    }

    /**
     * @param int<2,3> $version
     */
    protected function isFlysystemVersion(int $version): bool
    {
        return $version == preg_replace('/(\d+).*/', '$1', $this->flysystemVersion);
    }

    /**
     * When league/flysystem 2.x is installed, the trait should kick in.
     * When league/flysystem 3.x is installed, the trait should call the genuine implementation.
     *
     *
     * @see \League\Flysystem\InMemory\InMemoryFilesystemAdapter::directoryExists
     *
     * @covers \BrianHenryIE\Strauss\Helpers\FlysystemAdapterBackCompatTrait::directoryExists()
     */
    public function test_adapter_is_only_used_when_genuine_implementation_absent(): void
    {
        $usedTraitImplementation = new \stdClass();
        $usedTraitImplementation->calledDirectoryExists = false;

        $sut = new class($usedTraitImplementation) extends \League\Flysystem\InMemory\InMemoryFilesystemAdapter
                           implements FlysystemAdapterBackCompatTraitInterface
        {
            use FlysystemAdapterBackCompatTrait;

            protected $usedTraitImplementation;

            public function __construct($usedTraitImplementation, string $defaultVisibility = Visibility::PUBLIC, MimeTypeDetector $mimeTypeDetector = null)
            {
                parent::__construct($defaultVisibility, $mimeTypeDetector);

                $this->usedTraitImplementation = $usedTraitImplementation;
            }

            public function normalizePath(string $path): string
            {
                $this->usedTraitImplementation->calledDirectoryExists = true;
                return $path;
            }
        };

        $sut->directoryExists('foo');

        $failureMessage = $this->isFlysystemVersion(2)
            ? 'league/flysystem 2.x should call the trait implementation of directoryExists()'
            : 'league/flysystem 3.x should call the genuine implementation of directoryExists()';

        $this->assertEquals($usedTraitImplementation->calledDirectoryExists, $this->isFlysystemVersion(2), $failureMessage);
    }

    /**
     * @covers \BrianHenryIE\Strauss\Helpers\FlysystemAdapterBackCompatTrait::directoryExistsImplementation()
     */
    public function test_implementation(): void
    {

        $sut = new class() extends \League\Flysystem\InMemory\InMemoryFilesystemAdapter
            implements FlysystemAdapterBackCompatTraitInterface
        {
            use FlysystemAdapterBackCompatTrait;

            protected PathNormalizer $normalizer;

            public function __construct(string $defaultVisibility = Visibility::PUBLIC, ?MimeTypeDetector $mimeTypeDetector = null)
            {
                parent::__construct($defaultVisibility, $mimeTypeDetector);

                $this->normalizer = new WhitespacePathNormalizer();
            }

            public function normalizePath(string $path): string
            {
                return $this->normalizer->normalizePath($path);
            }

            /*
             * Don't use {@see FlysystemAdapterBackCompatTrait::directoryExists()`.
             */
            public function directoryExists(string $location) : bool
            {
                return $this->directoryExistsImplementation($location);
            }
        };

        $config = new Config();

        $sut->createDirectory('/foo/bar', $config);

        $this->assertTrue($sut->directoryExists('/foo/bar'));
        $this->assertTrue($sut->directoryExists('foo/bar'));
        $this->assertTrue($sut->directoryExists('foo'));
        $this->assertFalse($sut->directoryExists('bar'));
        $this->assertTrue($sut->directoryExists('/'));
    }
}
