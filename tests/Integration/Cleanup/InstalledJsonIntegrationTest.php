<?php

namespace BrianHenryIE\Strauss\Pipeline\Cleanup;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\Cleanup\InstalledJson
 */
class InstalledJsonIntegrationTest extends IntegrationTestCase
{
    public function testVendorInstalledJsonPackagesRemainAListAfterDeletedPackagesAreRemoved(): void
    {
        chdir($this->testsWorkingDir);
        $this->createDeletedParentWithExcludedDependencyFixture();

        exec('composer install --no-interaction --no-progress --no-ansi', $output, $exitCode);
        $this->assertEquals(0, $exitCode, implode(PHP_EOL, $output));

        $exitCode = $this->runStrauss($straussOutput);
        $this->assertEquals(0, $exitCode, $straussOutput);

        $packageNames = $this->assertInstalledJsonPackagesIsList(
            $this->getFileSystem()->read($this->testsWorkingDir . '/vendor/composer/installed.json')
        );

        $this->assertSame(['acme/preserved-files'], $packageNames);
    }

    public function testTargetInstalledJsonPackagesRemainAListAfterNonStraussPackagesAreRemoved(): void
    {
        chdir($this->testsWorkingDir);
        $this->createTargetPackageRemovalFixture();

        exec('composer install --no-interaction --no-progress --no-ansi', $output, $exitCode);
        $this->assertEquals(0, $exitCode, implode(PHP_EOL, $output));

        $exitCode = $this->runStrauss($straussOutput);
        $this->assertEquals(0, $exitCode, $straussOutput);

        $packageNames = $this->assertInstalledJsonPackagesIsList(
            $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/composer/installed.json')
        );

        $this->assertSame(
            [
                'acme/aaa-copied-parent',
                'acme/zzz-copied-child',
            ],
            $packageNames
        );
    }

    /**
     * When {@see InstalledJson::cleanupVendorInstalledJson()} is run, it changes the relative paths to the packages.
     * When `composer dump-autoload` is then run, it does not include any files that are outside the true `vendor` directory
     */
    public function testComposerDumpAutoloadOnTargetDirectory(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/testcomposerdumpautoloadontargetdirectory",
  "require": {
    "chillerlan/php-qrcode": "^4"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "delete_vendor_packages": true
    }
  }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        exec('composer dump-autoload');

        $vendorInstalledJsonStringAfter = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor/composer/installed.json');
        $vendorPrefixedInstalledJsonPsr4PhpStringAfter = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/composer/installed.json');

        $this->assertStringContainsString('BrianHenryIE\\\\Strauss\\\\chillerlan\\\\Settings\\\\', $vendorPrefixedInstalledJsonPsr4PhpStringAfter);
        $this->assertStringNotContainsString('"chillerlan\\\\Settings\\\\', $vendorInstalledJsonStringAfter);
    }

    /**
     */
    public function testComposerDumpAutoloadOnTargetDirectoryIsVendorDir(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/testcomposerdumpautoloadontargetdirectoryisvendordir",
  "require": {
    "chillerlan/php-qrcode": "^4"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "target_directory": "vendor"
    }
  }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $vendorInstalledJsonStringAfter = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor/composer/installed.json');

        $this->assertStringContainsString('BrianHenryIE\\\\Strauss\\\\chillerlan\\\\Settings\\\\', $vendorInstalledJsonStringAfter);
        $this->assertStringNotContainsString('"chillerlan\\\\Settings\\\\', $vendorInstalledJsonStringAfter);
    }

    public function testComposerDumpAutoloadWithDeleteFalse(): void
    {
        $composerJsonString = <<<'EOD'
{
    "name": "brianhenryie/testcomposerdumpautoloadwithdeletefalse",
    "require": {
        "chillerlan/php-qrcode": "^4"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "BrianHenryIE\\Strauss\\",
            "delete_vendor_packages": false,
            "delete_vendor_files": false,
            "target_directory": "vendor-prefixed"
        }
    }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        exec('composer dump-autoload');

        $vendorInstalledJsonStringAfter = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor/composer/installed.json');
        $vendorPrefixedInstalledJsonPsr4PhpStringAfter = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/composer/installed.json');

        $this->assertStringContainsString('BrianHenryIE\\\\Strauss\\\\chillerlan\\\\Settings\\\\', $vendorPrefixedInstalledJsonPsr4PhpStringAfter);

        // Since we're not deleting the original files, don't change their vendor/composer/installed.json entries
        $this->assertStringNotContainsString('BrianHenryIE\\\\Strauss\\\\chillerlan\\\\Settings\\\\', $vendorInstalledJsonStringAfter);
        $this->assertStringContainsString('"chillerlan\\\\Settings\\\\', $vendorInstalledJsonStringAfter);
    }

    /**
     * @see https://github.com/CarbonPHP/carbon/blob/4be0c005164249208ce1b5ca633cd57bdd42ff33/composer.json#L34-L38
     */
    public function testPackageWithEmptyPsr4Namespace(): void
    {
        $this->markTestIncomplete('Not really sure if there is a true problem here.');

        $composerJsonString = <<<'EOD'
{
  "name": "installedjson/testemptynamespace",
  "require": {
    "nesbot/carbon": "1.39.1"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "delete_vendor_packages": true
    }
  },
  "config": {
    "allow-plugins": {
      "kylekatarnls/update-helper": true
    }
  }
}
EOD;

        // "autoload": {
        //   "psr-4": {
        //     "": "src/"
        //    }
        //  }

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        // vendor/nesbot/carbon
        // vendor/nesbot/carbon/LICENSE
        // vendor/nesbot/carbon/bin
        // vendor/nesbot/carbon/composer.json
        // vendor/nesbot/carbon/readme.md
        // vendor/nesbot/carbon/src

        // vendor/nesbot/carbon/src/Carbon/Carbon.php
        // DOES HAVE
        // namespace Carbon;

        // vendor/composer/autoload_psr4.php
        // HAS
        // return array(
        //    ...
        //    '' => array($vendorDir . '/nesbot/carbon/src'),
        // );

        // vendor/composer/installed.json
        //  {
        //    "name": "nesbot/carbon",
        //    "version": "1.39.1",
        //    ...
        //    "autoload": {
        //      "psr-4": {
        //        "": "src/"
        //        }
        //      },
        //    ...
        //    "install-path": "../nesbot/carbon"
        //  },

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        exec('composer dump-autoload');

        $vendorInstalledJsonStringAfter = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor/composer/installed.json');
        $vendorPrefixedInstalledJsonPsr4PhpStringAfter = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/composer/installed.json');

        $this->assertStringNotContainsString('"": "src/"', $vendorInstalledJsonStringAfter);
        $this->assertStringContainsString('"BrianHenryIE\\\\Strauss\\\\": "src/"', $vendorPrefixedInstalledJsonPsr4PhpStringAfter);
    }

    private function createDeletedParentWithExcludedDependencyFixture(): void
    {
        $this->writeJsonFile(
            $this->testsWorkingDir . '/composer.json',
            [
                'name' => 'acme/root',
                'version' => '1.0.0',
                'repositories' => [
                    $this->pathRepository('packages/deleted-parent'),
                    $this->pathRepository('packages/ordinary-child'),
                    $this->pathRepository('packages/preserved-files'),
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

        $this->writePackage(
            'packages/deleted-parent',
            'acme/deleted-parent',
            [
                'acme/ordinary-child' => '1.0.0',
                'acme/preserved-files' => '1.0.0',
            ],
            'Acme\\DeletedParent\\',
            'ParentThing'
        );
        $this->writePackage('packages/ordinary-child', 'acme/ordinary-child', [], 'Acme\\OrdinaryChild\\', 'ChildThing');
        $this->writePackage('packages/preserved-files', 'acme/preserved-files', [], 'Acme\\Preserved\\', 'Thing');
    }

    private function createTargetPackageRemovalFixture(): void
    {
        $this->writeJsonFile(
            $this->testsWorkingDir . '/composer.json',
            [
                'name' => 'acme/root',
                'version' => '1.0.0',
                'repositories' => [
                    $this->pathRepository('packages/aaa-copied-parent'),
                    $this->pathRepository('packages/mm-unrelated-root'),
                    $this->pathRepository('packages/zzz-copied-child'),
                ],
                'require' => [
                    'acme/aaa-copied-parent' => '1.0.0',
                    'acme/mm-unrelated-root' => '1.0.0',
                ],
                'extra' => [
                    'strauss' => [
                        'namespace_prefix' => 'Acme\\Prefixed\\',
                        'classmap_prefix' => 'Acme_Prefixed_',
                        'target_directory' => 'vendor-prefixed',
                        'packages' => [
                            'acme/aaa-copied-parent',
                        ],
                        'delete_vendor_packages' => true,
                        'optimize_autoloader' => false,
                    ],
                ],
            ]
        );

        $this->writePackage(
            'packages/aaa-copied-parent',
            'acme/aaa-copied-parent',
            [
                'acme/zzz-copied-child' => '1.0.0',
            ],
            'Acme\\CopiedParent\\',
            'ParentThing'
        );
        $this->writePackage('packages/mm-unrelated-root', 'acme/mm-unrelated-root', [], 'Acme\\UnrelatedRoot\\', 'RootThing');
        $this->writePackage('packages/zzz-copied-child', 'acme/zzz-copied-child', [], 'Acme\\CopiedChild\\', 'ChildThing');
    }

    /**
     * @return array<string, mixed>
     */
    private function pathRepository(string $path): array
    {
        return [
            'type' => 'path',
            'url' => $path,
            'options' => [
                'symlink' => false,
            ],
        ];
    }

    /**
     * @param array<string, string> $requires
     */
    private function writePackage(string $path, string $name, array $requires, string $namespace, string $className): void
    {
        $composerJson = [
            'name' => $name,
            'version' => '1.0.0',
            'autoload' => [
                'psr-4' => [
                    $namespace => 'src/',
                ],
            ],
        ];

        if (!empty($requires)) {
            $composerJson['require'] = $requires;
        }

        $this->writeJsonFile($this->testsWorkingDir . '/' . $path . '/composer.json', $composerJson);
        $this->writeFile(
            $this->testsWorkingDir . '/' . $path . '/src/' . $className . '.php',
            sprintf(
                "<?php\n\nnamespace %s;\n\nclass %s\n{\n}\n",
                trim($namespace, '\\'),
                $className
            )
        );
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

    /**
     * @return string[]
     */
    private function assertInstalledJsonPackagesIsList(string $installedJson): array
    {
        $installedJsonArray = json_decode($installedJson, true);

        $this->assertIsArray($installedJsonArray, 'installed.json should decode to an array');
        $this->assertArrayHasKey('packages', $installedJsonArray, 'installed.json should contain packages');
        $this->assertIsArray($installedJsonArray['packages']);
        $isList = function_exists('array_is_list')
            ? array_is_list($installedJsonArray['packages'])
            : array_keys($installedJsonArray['packages']) === array_keys(array_values($installedJsonArray['packages']));
        $this->assertTrue($isList, 'installed.json packages should be a JSON list');

        return array_map(
            static fn(array $package): string => $package['name'],
            $installedJsonArray['packages']
        );
    }
}
