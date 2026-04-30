<?php

namespace BrianHenryIE\Strauss\Composer;

use BrianHenryIE\Strauss\TestCase;
use Composer\Factory;
use Composer\IO\NullIO;
use JsonException;

/**
 * @covers \BrianHenryIE\Strauss\Composer\ComposerPackage
 */
class ComposerPackageFactoryParityTest extends TestCase
{
    /**
     * @return array<string, array{0:string}>
     */
    public static function fixtureProvider(): array
    {
        return [
            'libmergepdf' => [__DIR__ . '/composerpackage-test-libmergepdf.json'],
            'easypost' => [__DIR__ . '/composerpackage-test-easypost-php.json'],
            'php-di' => [__DIR__ . '/composerpackage-test-php-di.json'],
        ];
    }

    /**
     * @dataProvider fixtureProvider
     */
    public function test_from_file_matches_legacy_factory_create(string $fixturePath): void
    {
        $legacyComposer = Factory::create(new NullIO(), $fixturePath, true);
        $legacy = new ComposerPackage($legacyComposer);

        $fast = ComposerPackage::fromFile($fixturePath);

        self::assertSame(
            $this->snapshotWithPaths($legacy),
            $this->snapshotWithPaths($fast)
        );
    }

    /**
     * @dataProvider fixtureProvider
     * @throws JsonException
     */
    public function test_from_composer_json_array_matches_full_load_behavior(string $fixturePath): void
    {
        $raw = file_get_contents($fixturePath);
        self::assertIsString($raw);
        $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $factory = new Factory();
        $legacyComposer = $factory->createComposer(new NullIO(), $json, true, null, true);
        $legacy = new ComposerPackage($legacyComposer);

        $fast = ComposerPackage::fromComposerJsonArray($json);

        self::assertSame(
            $this->snapshotWithoutPaths($legacy),
            $this->snapshotWithoutPaths($fast)
        );
    }

    /**
     * @param ComposerPackage $package
     * @return array{
     *     name:string,
     *     autoload:array<string,mixed>,
     *     requires:array<int,string>,
     *     license:string,
     *     relative_path:?string,
     *     absolute_path:?string
     * }
     */
    private function snapshotWithPaths(ComposerPackage $package): array
    {
        $requires = $package->getRequiresNames();
        sort($requires);

        return [
            'name' => $package->getPackageName(),
            'autoload' => $package->getAutoload(),
            'requires' => array_values($requires),
            'license' => $package->getLicense(),
            'relative_path' => $package->getRelativePath(),
            'absolute_path' => $package->getPackageAbsolutePath(),
        ];
    }

    /**
     * @param ComposerPackage $package
     * @return array{
     *     name:string,
     *     autoload:array<string,mixed>,
     *     requires:array<int,string>,
     *     license:string
     * }
     */
    private function snapshotWithoutPaths(ComposerPackage $package): array
    {
        $requires = $package->getRequiresNames();
        sort($requires);

        return [
            'name' => $package->getPackageName(),
            'autoload' => $package->getAutoload(),
            'requires' => array_values($requires),
            'license' => $package->getLicense(),
        ];
    }
}
