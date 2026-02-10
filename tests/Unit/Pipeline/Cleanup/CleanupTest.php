<?php

namespace BrianHenryIE\Strauss\Pipeline\Cleanup;

use BrianHenryIE\Strauss\Config\CleanupConfigInterface;
use BrianHenryIE\Strauss\Config\OptimizeAutoloaderConfigInterface;
use Mockery;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\Cleanup\Cleanup
 */
class CleanupTest extends \BrianHenryIE\Strauss\TestCase
{
    public function test_optimize_autoloader_defaults_to_true_without_capability_interface(): void
    {
        $config = Mockery::mock(CleanupConfigInterface::class);
        $config->expects('isDeleteVendorFiles')->once()->andReturnFalse();
        $config->expects('isDeleteVendorPackages')->once()->andReturnFalse();

        $sut = new class($config, $this->getFileSystem(), new NullLogger()) extends Cleanup {
            public function optimizeEnabledForTest(): bool
            {
                return $this->isOptimizeAutoloaderEnabled();
            }
        };

        $this->assertTrue($sut->optimizeEnabledForTest());
    }

    public function test_optimize_autoloader_uses_capability_interface_when_available(): void
    {
        $config = Mockery::mock(
            CleanupConfigInterface::class,
            OptimizeAutoloaderConfigInterface::class
        );
        $config->expects('isDeleteVendorFiles')->once()->andReturnFalse();
        $config->expects('isDeleteVendorPackages')->once()->andReturnFalse();
        $config->expects('isOptimizeAutoloader')->once()->andReturnFalse();

        $sut = new class($config, $this->getFileSystem(), new NullLogger()) extends Cleanup {
            public function optimizeEnabledForTest(): bool
            {
                return $this->isOptimizeAutoloaderEnabled();
            }
        };

        $this->assertFalse($sut->optimizeEnabledForTest());
    }
}
