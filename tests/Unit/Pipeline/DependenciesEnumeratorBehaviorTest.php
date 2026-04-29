<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\TestCase;
use Exception;
use Psr\Log\NullLogger;

/**
 * @covers \BrianHenryIE\Strauss\Pipeline\DependenciesEnumerator
 */
class DependenciesEnumeratorBehaviorTest extends TestCase
{
    private string $cwdBeforeTest;

    /** @var string[] */
    private array $temporaryProjectDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cwdBeforeTest = getcwd() ?: '';
    }

    protected function tearDown(): void
    {
        if ($this->cwdBeforeTest !== '' && is_dir($this->cwdBeforeTest)) {
            chdir($this->cwdBeforeTest);
        }

        foreach ($this->temporaryProjectDirs as $temporaryProjectDir) {
            if ($this->getFileSystem()->directoryExists($temporaryProjectDir)) {
                $this->getFileSystem()->deleteDirectory($temporaryProjectDir);
            }
        }

        parent::tearDown();
    }

    /**
     * @throws Exception
     */
    public function test_discovers_transitive_dependencies_from_vendor_composer_files(): void
    {
        $projectDir = $this->createTempProjectDirectory();

        $this->writeJsonFile($projectDir . '/composer.json', ['name' => 'local/project']);
        $this->writeJsonFile($projectDir . '/composer.lock', ['packages' => []]);

        $this->writeJsonFile($projectDir . '/vendor/acme/root/composer.json', [
            'name' => 'acme/root',
            'type' => 'library',
            'require' => [
                'acme/dep' => '^1.0',
            ],
            'autoload' => [
                'psr-4' => ['Acme\\Root\\' => 'src/'],
            ],
        ]);

        $this->writeJsonFile($projectDir . '/vendor/acme/dep/composer.json', [
            'name' => 'acme/dep',
            'type' => 'library',
            'require' => (object) [],
            'autoload' => [
                'psr-4' => ['Acme\\Dep\\' => 'src/'],
            ],
        ]);

        $dependencies = $this->runEnumerator($projectDir, ['acme/root']);

        self::assertSame(['acme/root', 'acme/dep'], array_keys($dependencies));
    }

    /**
     * @throws Exception
     */
    public function test_skips_package_when_provided_by_root_composer_json_and_vendor_file_missing(): void
    {
        $projectDir = $this->createTempProjectDirectory();

        $this->writeJsonFile($projectDir . '/composer.json', [
            'name' => 'local/project',
            'provide' => ['virtual/provided' => '*'],
        ]);
        $this->writeJsonFile($projectDir . '/composer.lock', ['packages' => []]);

        $dependencies = $this->runEnumerator($projectDir, ['virtual/provided']);

        self::assertSame([], array_keys($dependencies));
    }

    /**
     * @throws Exception
     */
    public function test_uses_composer_lock_fallback_when_vendor_composer_json_is_missing(): void
    {
        $projectDir = $this->createTempProjectDirectory();

        $this->writeJsonFile($projectDir . '/composer.json', ['name' => 'local/project']);
        $this->writeJsonFile($projectDir . '/composer.lock', [
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

        $this->writeJsonFile($projectDir . '/vendor/child/pkg/composer.json', [
            'name' => 'child/pkg',
            'type' => 'library',
            'require' => (object) [],
            'autoload' => [
                'psr-4' => ['Child\\Pkg\\' => 'src/'],
            ],
        ]);

        $dependencies = $this->runEnumerator($projectDir, ['missing/pkg']);

        self::assertArrayHasKey('missing/pkg', $dependencies);
        self::assertArrayHasKey('child/pkg', $dependencies);
    }

    /**
     * @throws Exception
     */
    public function test_skips_non_metapackage_without_require_or_autoload_when_vendor_directory_missing(): void
    {
        $projectDir = $this->createTempProjectDirectory();

        $this->writeJsonFile($projectDir . '/composer.json', ['name' => 'local/project']);
        $this->writeJsonFile($projectDir . '/composer.lock', [
            'packages' => [
                [
                    'name' => 'meta/like-package',
                    'type' => 'library',
                    'require' => [],
                ],
            ],
        ]);

        $dependencies = $this->runEnumerator($projectDir, ['meta/like-package']);

        self::assertSame([], array_keys($dependencies));
    }

    /**
     * @throws Exception
     */
    public function test_skips_virtual_and_platform_packages(): void
    {
        $projectDir = $this->createTempProjectDirectory();

        $this->writeJsonFile($projectDir . '/composer.json', ['name' => 'local/project']);
        $this->writeJsonFile($projectDir . '/composer.lock', ['packages' => []]);

        $this->writeJsonFile($projectDir . '/vendor/acme/real/composer.json', [
            'name' => 'acme/real',
            'type' => 'library',
            'require' => (object) [],
            'autoload' => [
                'psr-4' => ['Acme\\Real\\' => 'src/'],
            ],
        ]);

        $dependencies = $this->runEnumerator(
            $projectDir,
            ['php', 'php-64bit', 'ext-json', 'php-http/client-implementation', 'acme/real']
        );

        self::assertSame(['acme/real'], array_keys($dependencies));
    }

    /**
     * @param string $projectDir
     * @param string[] $seedPackages
     * @return array<string,ComposerPackage>
     * @throws Exception
     */
    private function runEnumerator(string $projectDir, array $seedPackages): array
    {
        chdir($projectDir);

        $config = new StraussConfig();
        $config->setRelativeVendorDirectory('vendor');
        $config->setPackages($seedPackages);

        $enumerator = new DependenciesEnumerator($config, $this->newLocalFilesystem(), new NullLogger());

        return $enumerator->getAllDependencies();
    }

    private function createTempProjectDirectory(): string
    {
        $projectDir = sys_get_temp_dir() . '/strauss-deps-enumerator-' . uniqid('', true);
        mkdir($projectDir . '/vendor', 0777, true);
        $projectDir = str_replace('\\', '/', $projectDir);
        $this->temporaryProjectDirs[] = $projectDir;

        return $projectDir;
    }

    /**
     * @param array<string,mixed> $data
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

    private function newLocalFilesystem(): FileSystem
    {
        return $this->getNewFileSystem();
    }
}
