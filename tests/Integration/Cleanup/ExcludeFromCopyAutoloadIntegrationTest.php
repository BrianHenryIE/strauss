<?php

namespace BrianHenryIE\Strauss\Pipeline\Cleanup;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\IntegrationTestCase;
use Composer\Factory;
use Composer\IO\NullIO;

/**
 * @covers \BrianHenryIE\Strauss\Pipeline\Cleanup\Cleanup
 */
class ExcludeFromCopyAutoloadIntegrationTest extends IntegrationTestCase
{
    public function test_excluded_package_remains_in_vendor_autoload_after_parent_package_is_deleted(): void
    {
        chdir($this->testsWorkingDir);
        $this->createLocalPathRepositoryFixture();
        $this->composerInstall();

        $this->assertDirectoryExistsInFileSystem($this->testsWorkingDir . '/vendor/acme/deleted-parent');
        $this->assertDirectoryExistsInFileSystem($this->testsWorkingDir . '/vendor/acme/preserved-files');

        $exitCode = $this->runStraussInSubprocess($output);
        $this->assertSame(0, $exitCode, $output);

        $this->assertDirectoryNotExistsInFileSystem($this->testsWorkingDir . '/vendor/acme/deleted-parent');
        $this->assertDirectoryExistsInFileSystem($this->testsWorkingDir . '/vendor/acme/preserved-files');

        $vendorInstalledPackageNames = $this->extractPackageNamesFromInstalledJson(
            $this->readFile($this->testsWorkingDir . '/vendor/composer/installed.json')
        );
        $this->assertContains('acme/preserved-files', $vendorInstalledPackageNames);
        $this->assertNotContains('acme/deleted-parent', $vendorInstalledPackageNames);

        $autoloadFilesPhp = $this->readFile($this->testsWorkingDir . '/vendor/composer/autoload_files.php');
        $this->assertStringContainsString('/acme/preserved-files/bootstrap.php', $autoloadFilesPhp);

        $autoloadPsr4Php = $this->readFile($this->testsWorkingDir . '/vendor/composer/autoload_psr4.php');
        $this->assertStringContainsString('Acme\\\\Preserved\\\\', $autoloadPsr4Php);

        $autoloadStaticPhp = $this->readFile($this->testsWorkingDir . '/vendor/composer/autoload_static.php');
        $this->assertStringContainsString('/acme/preserved-files/bootstrap.php', $autoloadStaticPhp);
        $this->assertStringContainsString('Acme\\\\Preserved\\\\', $autoloadStaticPhp);

        $this->assertPreservedPackageIsRuntimeAutoloadable();
    }

    public function test_rebuild_vendor_autoloader_keeps_orphaned_excluded_package_autoloads(): void
    {
        chdir($this->testsWorkingDir);
        $this->createLocalPathRepositoryFixture();
        $this->composerInstall();

        $this->deleteDir($this->testsWorkingDir . '/vendor/acme/deleted-parent');
        $this->removePackageFromVendorInstalledJson('acme/deleted-parent');

        $composer = Factory::create(new NullIO(), $this->testsWorkingDir . '/composer.json');
        $config = new StraussConfig($composer);
        $cleanup = new Cleanup($config, $this->getFileSystem(), $this->logger);
        $cleanup->rebuildVendorAutoloader();

        $autoloadFilesPhp = $this->readFile($this->testsWorkingDir . '/vendor/composer/autoload_files.php');
        $this->assertStringContainsString('/acme/preserved-files/bootstrap.php', $autoloadFilesPhp);

        $autoloadPsr4Php = $this->readFile($this->testsWorkingDir . '/vendor/composer/autoload_psr4.php');
        $this->assertStringContainsString('Acme\\\\Preserved\\\\', $autoloadPsr4Php);

        $this->assertPreservedPackageIsRuntimeAutoloadable();
    }

    private function createLocalPathRepositoryFixture(): void
    {
        $this->writeJsonFile(
            $this->testsWorkingDir . '/composer.json',
            [
                'name' => 'acme/root',
                'version' => '1.0.0',
                'repositories' => [
                    [
                        'type' => 'path',
                        'url' => 'packages/deleted-parent',
                        'options' => [
                            'symlink' => false,
                        ],
                    ],
                    [
                        'type' => 'path',
                        'url' => 'packages/preserved-files',
                        'options' => [
                            'symlink' => false,
                        ],
                    ],
                ],
                'require' => [
                    'acme/deleted-parent' => '1.0.0',
                ],
                'extra' => [
                    'strauss' => [
                        'namespace_prefix' => 'Acme\\Prefixed\\',
                        'classmap_prefix' => 'Acme_Prefixed_',
                        'target_directory' => 'vendor-prefixed',
                        'packages' => [
                            'acme/deleted-parent',
                        ],
                        'delete_vendor_packages' => true,
                        'optimize_autoloader' => false,
                        'exclude_from_copy' => [
                            'packages' => [
                                'acme/preserved-files',
                            ],
                        ],
                    ],
                ],
            ]
        );

        $this->writeJsonFile(
            $this->testsWorkingDir . '/packages/deleted-parent/composer.json',
            [
                'name' => 'acme/deleted-parent',
                'version' => '1.0.0',
                'require' => [
                    'acme/preserved-files' => '1.0.0',
                ],
                'autoload' => [
                    'psr-4' => [
                        'Acme\\DeletedParent\\' => 'src/',
                    ],
                ],
            ]
        );
        $this->writeFile(
            $this->testsWorkingDir . '/packages/deleted-parent/src/ParentThing.php',
            "<?php\n\nnamespace Acme\\DeletedParent;\n\nclass ParentThing\n{\n}\n"
        );

        $this->writeJsonFile(
            $this->testsWorkingDir . '/packages/preserved-files/composer.json',
            [
                'name' => 'acme/preserved-files',
                'version' => '1.0.0',
                'autoload' => [
                    'files' => [
                        'bootstrap.php',
                    ],
                    'psr-4' => [
                        'Acme\\Preserved\\' => 'src/',
                    ],
                ],
            ]
        );
        $this->writeFile(
            $this->testsWorkingDir . '/packages/preserved-files/bootstrap.php',
            "<?php\n\ndefine('ACME_PRESERVED_BOOTSTRAPPED', true);\n"
        );
        $this->writeFile(
            $this->testsWorkingDir . '/packages/preserved-files/src/Thing.php',
            "<?php\n\nnamespace Acme\\Preserved;\n\nclass Thing\n{\n    public static function value(): string\n    {\n        return 'preserved';\n    }\n}\n"
        );
    }

    private function composerInstall(): void
    {
        exec('composer install --no-interaction --no-progress --no-ansi', $output, $exitCode);
        $this->assertSame(0, $exitCode, implode(PHP_EOL, $output));
    }

    private function runStraussInSubprocess(?string &$allOutput = null): int
    {
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->projectDir . '/bin/strauss') . ' 2>&1',
            $output,
            $exitCode
        );
        $allOutput = implode(PHP_EOL, $output);

        return $exitCode;
    }

    private function removePackageFromVendorInstalledJson(string $packageName): void
    {
        $installedJsonPath = $this->testsWorkingDir . '/vendor/composer/installed.json';
        $installedJson = json_decode($this->readFile($installedJsonPath), true);
        $this->assertIsArray($installedJson);
        $this->assertArrayHasKey('packages', $installedJson);

        $installedJson['packages'] = array_values(array_filter(
            $installedJson['packages'],
            static fn(array $package): bool => ($package['name'] ?? null) !== $packageName
        ));

        file_put_contents(
            $installedJsonPath,
            json_encode($installedJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @return string[]
     */
    private function extractPackageNamesFromInstalledJson(string $installedJson): array
    {
        $installedJsonArray = json_decode($installedJson, true);

        $this->assertIsArray($installedJsonArray, 'installed.json should decode to an array');
        $this->assertArrayHasKey('packages', $installedJsonArray, 'installed.json should contain packages');
        $this->assertIsArray($installedJsonArray['packages']);

        return array_values(array_filter(array_map(
            static fn(array $package): ?string => $package['name'] ?? null,
            $installedJsonArray['packages']
        )));
    }

    private function assertPreservedPackageIsRuntimeAutoloadable(): void
    {
        $autoloadPath = str_replace('\\', '/', $this->testsWorkingDir . '/vendor/autoload.php');
        $phpCode = sprintf(
            <<<'PHP'
require %s;
$ok = defined('ACME_PRESERVED_BOOTSTRAPPED')
    && class_exists('Acme\\Preserved\\Thing')
    && \Acme\Preserved\Thing::value() === 'preserved';
exit($ok ? 0 : 1);
PHP,
            var_export($autoloadPath, true)
        );

        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($phpCode), $output, $exitCode);
        $this->assertSame(0, $exitCode, implode(PHP_EOL, $output));
    }

    /**
     * @param array<string, mixed> $contents
     */
    private function writeJsonFile(string $path, array $contents): void
    {
        $this->writeFile(
            $path,
            json_encode($contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );
    }

    private function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);
    }

    private function readFile(string $path): string
    {
        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        return $contents;
    }
}
