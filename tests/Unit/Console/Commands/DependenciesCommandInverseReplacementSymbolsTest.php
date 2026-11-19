<?php

/**
 * {@see \BrianHenryIE\Strauss\Pipeline\ChangeEnumerator} works out every already-renamed symbol's inverse replacement
 * whatever the target directory, but only when prefixing in place can other packages still use those names.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/343
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Unit\Console\Commands;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Mockery;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Console\Commands\DependenciesCommand
 */
class DependenciesCommandInverseReplacementSymbolsTest extends TestCase
{
    protected function getSut(bool $isTargetDirectoryVendor): DependenciesCommand
    {
        $package = Mockery::mock(ComposerPackage::class);
        $package->allows('getPackageName')->andReturn('acme/lib');
        $package->allows('getPackageAbsolutePath')->andReturn('/project/vendor/acme/lib');
        $package->allows('addFile');
        $package->allows('isPsr0Autoloaded')->andReturnFalse();
        $package->allows('__toString')->andReturn('acme/lib');

        $config = Mockery::mock(StraussConfig::class);
        $config->allows('isTargetDirectoryVendor')->andReturn($isTargetDirectoryVendor);
        $config->allows('getPackagesToPrefix')->andReturn(['acme/lib' => $package]);

        $file = new FileWithDependency(
            $package,
            'acme/lib/functions.php',
            '/project/vendor/acme/lib/functions.php',
            '/project/vendor/acme/lib/functions.php'
        );
        $thing = new ClassSymbol('Prefix_Thing', $file, new NamespaceSymbol('\\'));
        $thing->setDoRename(true);
        $thing->setInverseReplacement('Thing');

        return new class ($config, new DiscoveredSymbols([$thing])) extends DependenciesCommand {
            public function __construct(StraussConfig $config, DiscoveredSymbols $discoveredSymbols)
            {
                parent::__construct();
                $this->config = $config;
                $this->logger = new NullLogger();
                $this->discoveredSymbols = $discoveredSymbols;
            }
        };
    }

    /**
     * @return DiscoveredSymbol[]
     */
    protected function getInverseReplacementSymbols(DependenciesCommand $sut): array
    {
        $method = new ReflectionMethod(DependenciesCommand::class, 'getInverseReplacementSymbols');
        $method->setAccessible(true);

        /** @var DiscoveredSymbol[] $result */
        $result = $method->invoke($sut);

        return $result;
    }

    /**
     * @covers ::getInverseReplacementSymbols
     */
    public function test_inverse_replacement_symbols_when_prefixing_in_place(): void
    {
        $result = $this->getInverseReplacementSymbols($this->getSut(true));

        self::assertCount(1, $result);
        self::assertSame('Thing', $result[0]->getOriginalFqdnName());
        self::assertSame('Prefix_Thing', $result[0]->getReplacementFqdnName());
    }

    /**
     * With another target directory, the files are read afresh from `vendor`, so nothing uses the earlier names.
     *
     * @covers ::getInverseReplacementSymbols
     */
    public function test_no_inverse_replacement_symbols_with_another_target_directory(): void
    {
        self::assertSame([], $this->getInverseReplacementSymbols($this->getSut(false)));
    }
}
