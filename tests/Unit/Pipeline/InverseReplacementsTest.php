<?php

/**
 * @see https://github.com/BrianHenryIE/strauss/issues/343
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\InverseReplacementsConfigInterface;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\TestCase;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\InverseReplacements
 */
class InverseReplacementsTest extends TestCase
{
    /**
     * @param string[] $packagesToPrefix
     */
    protected function getSut(bool $isTargetDirectoryVendor, array $packagesToPrefix = ['acme/lib']): InverseReplacements
    {
        $config = Mockery::mock(InverseReplacementsConfigInterface::class);
        $config->allows('isTargetDirectoryVendor')->andReturn($isTargetDirectoryVendor);
        $config->allows('getPackagesToPrefix')->andReturn(
            array_fill_keys($packagesToPrefix, Mockery::mock(ComposerPackage::class))
        );

        return new InverseReplacements($config, $this->getLogger());
    }

    /**
     * `acme/lib`'s global class, prefixed by an earlier run.
     */
    protected function getSymbols(?string $inverseReplacement = 'Thing'): DiscoveredSymbols
    {
        $package = Mockery::mock(ComposerPackage::class);
        $package->allows('getPackageName')->andReturn('acme/lib');
        $package->allows('getPackageAbsolutePath')->andReturn('/project/vendor/acme/lib');
        $package->allows('addFile');
        $package->allows('isPsr0Autoloaded')->andReturnFalse();
        $package->allows('__toString')->andReturn('acme/lib');

        $file = new FileWithDependency(
            $package,
            'acme/lib/functions.php',
            '/project/vendor/acme/lib/functions.php',
            '/project/vendor/acme/lib/functions.php'
        );
        $thing = new ClassSymbol('Prefix_Thing', $file, new NamespaceSymbol('\\'));
        $thing->setDoRename(true);
        $thing->setInverseReplacement($inverseReplacement);

        return new DiscoveredSymbols([$thing]);
    }

    /**
     * When prefixing in place, the copies are added to a copy of the collection.
     *
     * @covers ::__construct
     * @covers ::withInverseReplacementSymbols
     */
    public function test_copies_are_added_when_prefixing_in_place(): void
    {
        $symbols = $this->getSymbols();

        $result = $this->getSut(true)->withInverseReplacementSymbols($symbols);

        self::assertNotSame($symbols, $result);

        $copy = $result->getClass('Thing');
        self::assertNotNull($copy);
        self::assertSame('Prefix_Thing', $copy->getReplacementFqdnName());
        self::assertSame($symbols->getClass('Prefix_Thing'), $result->getClass('Prefix_Thing'));

        // The given collection is unchanged.
        self::assertNull($symbols->getClass('Thing'));
    }

    /**
     * With another target directory, the files are read afresh from `vendor`, so nothing uses the earlier names.
     *
     * @covers ::withInverseReplacementSymbols
     */
    public function test_another_target_directory_returns_the_given_symbols(): void
    {
        $symbols = $this->getSymbols();

        self::assertSame($symbols, $this->getSut(false)->withInverseReplacementSymbols($symbols));
    }

    /**
     * @covers ::withInverseReplacementSymbols
     */
    public function test_no_inverse_replacements_returns_the_given_symbols(): void
    {
        $symbols = $this->getSymbols(null);

        self::assertSame($symbols, $this->getSut(true)->withInverseReplacementSymbols($symbols));
    }

    /**
     * @covers ::withInverseReplacementSymbols
     */
    public function test_packages_not_being_prefixed_are_ignored(): void
    {
        $symbols = $this->getSymbols();

        self::assertSame($symbols, $this->getSut(true, ['other/package'])->withInverseReplacementSymbols($symbols));
    }
}
