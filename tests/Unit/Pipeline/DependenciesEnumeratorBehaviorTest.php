<?php /** @noinspection PhpMultipleClassDeclarationsInspection */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\DependenciesCollection;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\IntegrationTestCase;
use JsonException;
use League\Flysystem\FilesystemException;

/**
 * @covers \BrianHenryIE\Strauss\Pipeline\DependenciesEnumerator
 */
class DependenciesEnumeratorBehaviorTest extends IntegrationTestCase
{
    /**
     * @throws FilesystemException
     * @throws JsonException
     */
    public function test_discovers_transitive_dependencies_from_vendor_composer_files(): void
    {
        $this->writeJsonFile($this->testsWorkingDir . '/composer.json', ['name' => 'local/project']);

        exec('composer update --no-dev');

        $this->writeJsonFile($this->testsWorkingDir . '/composer.lock', ['packages' => []]);

        $this->writeJsonFile($this->testsWorkingDir . '/vendor/acme/root/composer.json', [
            'name' => 'acme/root',
            'type' => 'library',
            'require' => [
                'acme/dep' => '^1.0',
            ],
            'autoload' => [
                'psr-4' => ['Acme\\Root\\' => 'src/'],
            ],
        ]);

        $this->writeJsonFile($this->testsWorkingDir . '/vendor/acme/dep/composer.json', [
            'name' => 'acme/dep',
            'type' => 'library',
            'require' => (object) [],
            'autoload' => [
                'psr-4' => ['Acme\\Dep\\' => 'src/'],
            ],
        ]);

        $dependencies = $this->runEnumerator($this->testsWorkingDir, ['acme/root']);

        $this->assertSame(['acme/root', 'acme/dep'], array_keys($dependencies->toArray()));
    }

    /**
     * @throws FilesystemException
     * @throws JsonException
     */
    public function test_skips_package_when_provided_by_root_composer_json_and_vendor_file_missing(): void
    {
        $this->writeJsonFile($this->testsWorkingDir . '/composer.json', [
            'name' => 'local/project',
            'provide' => ['virtual/provided' => '*'],
        ]);

        exec('composer install --no-dev');

        $this->writeJsonFile($this->testsWorkingDir . '/composer.lock', ['packages' => []]);

        $dependencies = $this->runEnumerator($this->testsWorkingDir, ['virtual/provided']);

        $this->assertSame([], array_keys($dependencies->toArray()));
    }

    /**
     * @throws FilesystemException
     * @throws JsonException
     */
    public function test_uses_composer_lock_fallback_when_vendor_composer_json_is_missing(): void
    {
        $this->writeJsonFile($this->testsWorkingDir . '/composer.json', ['name' => 'local/project']);

        exec('composer install --no-dev');

        $this->writeJsonFile($this->testsWorkingDir . '/composer.lock', [
            'packages' => [
                [
                    'name' => 'missing/pkg',
                    'type' => 'library',
                    'require' => [
                        'php' => '^8.0',
                        'child/pkg' => '^1.0',
                    ],
                    'autoload' => [
                        'psr-4' => ['Missing\\Pkg\\' => 'src/'],
                    ],
                ],
            ],
        ]);

        $this->writeJsonFile($this->testsWorkingDir . '/vendor/child/pkg/composer.json', [
            'name' => 'child/pkg',
            'type' => 'library',
            'require' => (object) [],
            'autoload' => [
                'psr-4' => ['Child\\Pkg\\' => 'src/'],
            ],
        ]);

        $dependencies = $this->runEnumerator($this->testsWorkingDir, ['missing/pkg']);

        $this->assertArrayHasKey('missing/pkg', $dependencies);
        $this->assertArrayHasKey('child/pkg', $dependencies);
    }

    /**
     * @throws FilesystemException
     * @throws JsonException
     */
    public function test_skips_non_metapackage_without_require_or_autoload_when_vendor_directory_missing(): void
    {
        $this->writeJsonFile($this->testsWorkingDir . '/composer.json', ['name' => 'local/project']);

        exec('composer install --no-dev');

        $this->writeJsonFile($this->testsWorkingDir . '/composer.lock', [
            'packages' => [
                [
                    'name' => 'meta/like-package',
                    'type' => 'library',
                    'require' => [],
                ],
            ],
        ]);

        $dependencies = $this->runEnumerator($this->testsWorkingDir, ['meta/like-package']);

        $this->assertSame([], array_keys($dependencies->toArray()));
    }

    /**
     * @throws FilesystemException
     * @throws JsonException
     */
    public function test_skips_virtual_and_platform_packages(): void
    {
        $this->writeJsonFile($this->testsWorkingDir . '/composer.json', ['name' => 'local/project']);

        exec('composer update --no-dev');
        
        $this->writeJsonFile($this->testsWorkingDir . '/composer.lock', ['packages' => []]);

        $this->writeJsonFile($this->testsWorkingDir . '/vendor/acme/real/composer.json', [
            'name' => 'acme/real',
            'type' => 'library',
            'require' => (object) [],
            'autoload' => [
                'psr-4' => ['Acme\\Real\\' => 'src/'],
            ],
        ]);

        $dependencies = $this->runEnumerator(
            $this->testsWorkingDir,
            ['php', 'php-64bit', 'ext-json', 'php-http/client-implementation', 'acme/real']
        );

        $this->assertSame(['acme/real'], array_keys($dependencies->toArray()));
    }

    /**
     * @throws FilesystemException
     */
    private function runEnumerator(string $testsWorkingDir, array $seedPackages): DependenciesCollection
    {
        chdir($testsWorkingDir);

        $config = new StraussConfig();
        $config->setRelativeVendorDirectory('vendor');
        $config->setPackages($seedPackages);

        $enumerator = new DependenciesEnumerator($config, $this->getFileSystem(), $this->getLogger());

        return $enumerator->getAllDependencies();
    }

    /**
     * @param array<string,mixed> $data
     *
     * @throws JsonException
     */
    private function writeJsonFile(string $path, array $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents(
            $path,
            (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }
}
