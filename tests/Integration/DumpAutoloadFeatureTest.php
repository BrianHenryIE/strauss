<?php

/**
 * After files have been copied and prefixed, we use Composer's tools to generate the autoload files.
 */

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @covers \BrianHenryIE\Strauss\Pipeline\Autoload\DumpAutoload
 */
class DumpAutoloadFeatureTest extends IntegrationTestCase
{
    /**
     * TODO: Ideally, a test where some no-dev packages are shared with dev packages and all autoloader types covered.
     */
    public function testDumpAutoload(): void
    {
        $composerJsonString = <<<'EOD'
            {
              "name": "brianhenryie/testdumpautoload",
              "require": {
                "symfony/polyfill-ctype": "*"
              },
              "require-dev": {
              },
              "extra": {
                "strauss": {
                  "namespace_prefix": "BrianHenryIE\\Dump_Autoload\\",
                  "classmap_prefix": "Dump_Autoload_",
                  "delete_vendor_packages": true
                }
              }
            }
            EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install', $composerInstallOutput, $composerInstallExitCode);
        $this->assertEquals(0, $composerInstallExitCode, implode(PHP_EOL, $composerInstallOutput));

        $exitCode = $this->runStrauss($output);
        assert($exitCode === 0, $output);

        exec('composer dump-autoload', $composerDumpAutoloadOutput, $composerDumpAutoloadExitCode);
        $this->assertEquals(0, $composerDumpAutoloadExitCode, implode(PHP_EOL, $composerDumpAutoloadOutput));

        $this->assertFileNotExistsInFileSystem($this->testsWorkingDir . '/vendor/composer/autoload_files.php');
        //        $vendorAutoloadFilesPhpString = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor/composer/autoload_files.php');
        $vendorPrefixedAutoloadFilesPhpString = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/composer/autoload_files.php');

        $this->assertStringContainsString('symfony/polyfill-ctype', $vendorPrefixedAutoloadFilesPhpString);
        //        $this->assertStringNotContainsString('symfony/polyfill-ctype', $vendorAutoloadFilesPhpString);
    }
    /**
     * Creating Composer objects to generate the autoloaders dispatches Composer's `init` event, which must not run
     * the project's script of that name.
     *
     * `delete_vendor_packages` means both autoloaders are regenerated.
     *
     * @covers \BrianHenryIE\Strauss\Pipeline\Cleanup\Cleanup::rebuildVendorAutoloader
     */
    public function testDoesNotRunComposerInitScript(): void
    {
        $markerFile = $this->testsWorkingDir . '/init-script-ran.txt';

        $composerJsonArray = [
            'name' => 'brianhenryie/testdumpautoloadscripts',
            'require' => [
                'symfony/polyfill-ctype' => '*',
            ],
            'scripts' => [
                'init' => sprintf('%s -r %s', escapeshellarg(PHP_BINARY), escapeshellarg("touch('{$markerFile}');")),
            ],
            'extra' => [
                'strauss' => [
                    'namespace_prefix' => 'BrianHenryIE\\Dump_Autoload\\',
                    'classmap_prefix' => 'Dump_Autoload_',
                    'delete_vendor_packages' => true,
                ],
            ],
        ];

        $this->getFileSystem()->write(
            $this->testsWorkingDir . '/composer.json',
            (string) json_encode($composerJsonArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        chdir($this->testsWorkingDir);

        exec('composer install', $composerInstallOutput, $composerInstallExitCode);
        $this->assertEquals(0, $composerInstallExitCode, implode(PHP_EOL, $composerInstallOutput));

        // Composer itself runs the script during `composer install`.
        if (file_exists($markerFile)) {
            unlink($markerFile);
        }

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $this->assertFileDoesNotExist($markerFile, 'Strauss ran the project\'s `init` Composer script.');
    }
}
