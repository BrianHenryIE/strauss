<?php

namespace BrianHenryIE\Strauss\Composer;

use BrianHenryIE\Strauss\TestCase;

/**
 * @covers \BrianHenryIE\Strauss\Composer\ComposerPackage
 */
class ComposerPackagePlatformPackageTest extends TestCase
{
    public function test_get_requires_names_keeps_php_variants(): void
    {
        $composer = ComposerPackage::fromComposerJsonArray(
            [
                'name' => 'acme/test',
                'type' => 'library',
                'require' => [
                    'php' => '^8.0',
                    'php-64bit' => '*',
                    'ext-json' => '*',
                    'acme/dep' => '^1.0',
                ],
            ]
        );

        $requiresNames = $composer->getRequiresNames();

        self::assertContains('php-64bit', $requiresNames);
        self::assertContains('acme/dep', $requiresNames);
        self::assertNotContains('php', $requiresNames);
        self::assertNotContains('ext-json', $requiresNames);
    }

    /**
     * @dataProvider platformPackageNameProvider
     */
    public function test_is_platform_package_name(string $packageName, bool $includePhpVariants, bool $expected): void
    {
        self::assertSame($expected, ComposerPackage::isPlatformPackageName($packageName, $includePhpVariants));
    }

    /**
     * @return array<string,array{0:string,1:bool,2:bool}>
     */
    public static function platformPackageNameProvider(): array
    {
        return [
            'php is platform' => ['php', false, true],
            'extension is platform' => ['ext-json', false, true],
            'php variant excluded by default' => ['php-64bit', false, false],
            'php variant included when requested' => ['php-64bit', true, true],
            'virtual package with slash is not platform' => ['php-http/client-implementation', true, false],
            'vendor package is not platform' => ['acme/package', true, false],
        ];
    }
}
