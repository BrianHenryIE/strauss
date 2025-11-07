<?php
/**
 * Prefix own classes
 *
 * [error] Syntax error, unexpected T_NAMESPACE, expecting ')' on line 550
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/146
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;
use GuzzleHttp\Client;
use ZipArchive;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue146Test extends IntegrationTestCase
{
    protected function moveFiles(string $path)
    {
        $path = str_replace('//', '/', $path);
        $newPath = str_replace('/strauss-0.24.1', '', $path);
        foreach (glob($path.'/*') as $file) {
            if (is_dir($file)) {
                $newDir = str_replace('/strauss-0.24.1', '', $file);
                if (!is_dir($newDir)) {
                    mkdir($newDir);
                }
                if ($path === $file) {
                    continue;
                }
                $this->moveFiles($file);
                rmdir($file);
                continue;
            }
            copy($file, str_replace($path, $newPath, $file));
            unlink($file);
        }
    }

    protected function copyRecursive(string $source, string $destination)
    {
        if (is_dir($source)) {
            if (!is_dir($destination)) {
                mkdir($destination);
            }
            foreach (glob($source.'/*') as $file) {
                $subDirPath = str_replace($source, '', $file);
                $tar = $destination.$subDirPath;
                $this->copyRecursive($file, $tar);
            }
            return;
        }
        copy($source, $destination);
    }

    public function test_prefix_own_classes_for_release(): void
    {
        $projectDir = preg_replace('#/$#', '', $this->projectDir);
        $buildDir = preg_replace('#/$#', '', $this->testsWorkingDir);

        $filesToInclude = [
            'src',
            'bin',
            'composer.json',
            'composer.lock',
            'bootstrap.php',
            'CHANGELOG.md',
        ];

        foreach ($filesToInclude as $fileName) {
            $source = $projectDir .'/'.$fileName;
            $destination = $buildDir .'/'. $fileName;
            $this->copyRecursive($source, $destination);
        }

        chdir($this->testsWorkingDir);

        $composerJsonString = file_get_contents($this->testsWorkingDir . '/composer.json');
        $composerJsonArray = json_decode($composerJsonString, true);
        $composerJsonArray['extra']['strauss'] = [
            "update_call_sites" => true,
        ];
        file_put_contents($this->testsWorkingDir . '/composer.json', json_encode($composerJsonArray));

        exec('composer install --no-dev --no-scripts');

        /**
         * @see DependenciesCommand::execute()
         */
        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        // vendor/composer/autoload_real.php
        // self::$loader = $loader = new \Composer\Autoload\ClassLoader(\dirname(__DIR__));
        $autoloadRealPhpString = file_get_contents($this->testsWorkingDir .'/vendor/composer/autoload_real.php');
        // Confirm problem is gone.
        self::assertStringNotContainsString('new \\Composer\\Autoload\\ClassLoader', $autoloadRealPhpString);
        // Confirm solution is correct.
        self::assertStringContainsString('new \\BrianHenryIE\\Strauss\\Vendor\\Composer\\Autoload\\ClassLoader', $autoloadRealPhpString, 'Class name not properly prefixed.');

        // vendor/composer/composer/src/Composer/Factory.php
        // public static function create(IOInterface $io, $config =1 null, $disablePlugins = false, bool $disableScripts = false): BrianHenryIE\Strauss\Vendor\Composer
        $php_string = file_get_contents($this->testsWorkingDir .'/vendor/composer/composer/src/Composer/Factory.php');
        // Confirm problem is gone.
        self::assertStringNotContainsString('public static function create(IOInterface $io, $config = null, $disablePlugins = false, bool $disableScripts = false): BrianHenryIE\\Strauss\\Vendor\\Composer', $php_string);
        // Confirm solution is correct.
        self::assertStringContainsString('public static function create(IOInterface $io, $config = null, $disablePlugins = false, bool $disableScripts = false): Composer', $php_string);
    }

    public function test_prefix_own_classes_for_test(): void
    {
        $this->markTestIncomplete('Bug apparent in previous test need to be fixed first.');

        $projectDir = preg_replace('#/$#', '', $this->projectDir);
        $buildDir = preg_replace('#/$#', '', $this->testsWorkingDir);

        $filesToInclude = [
            'src',
            'bin',
            'composer.json',
            'composer.lock',
            'bootstrap.php',
            'CHANGELOG.md',
            'tests',
        ];

        foreach ($filesToInclude as $fileName) {
            $source = $projectDir .'/'.$fileName;
            $destination = $buildDir .'/'. $fileName;
            $this->copyRecursive($source, $destination);
        }

        chdir($this->testsWorkingDir);

        $composerJsonString = file_get_contents($this->testsWorkingDir . '/composer.json');
        $composerJsonArray = json_decode($composerJsonString, true);
        $composerJsonArray['extra']['strauss'] = [
            "update_call_sites" => ["src", "tests"],
        ];
        file_put_contents($this->testsWorkingDir . '/composer.json', json_encode($composerJsonArray));

        exec('composer install --no-dev --no-scripts');

        /**
         * @see DependenciesCommand::execute()
         */
        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        // TODO: run the tests.
    }

    public function test_prefix_own_classes_old(): void
    {
        $this->markTestSkipped('Skip until release of 0.25.0. Earlier versions will always fail.');

        $repoUrl = 'https://github.com/BrianHenryIE/strauss/archive/refs/tags/0.25.0.zip';

        $client = new Client();

        $saveTo = $this->testsWorkingDir . '/strauss-0.25.0.zip';

        $client->request('GET', $repoUrl, ['sink' => $saveTo]);

        $zip = new ZipArchive;
        $zip->open($saveTo);
        $zip->extractTo($this->testsWorkingDir);
        $zip->close();

        unlink($saveTo);

        $this->moveFiles($this->testsWorkingDir.'/strauss-0.25.0');
        rmdir($this->testsWorkingDir.'/strauss-0.25.0');

        chdir($this->testsWorkingDir);

        $composerJsonString = file_get_contents($this->testsWorkingDir . '/composer.json');
        $composerJsonArray = json_decode($composerJsonString, true);
        $composerJsonArray['extra']['strauss'] = [
            "namespace_prefix" => "BrianHenryIE\\Strauss",
            "target_directory" => "vendor",
            "update_call_sites" => true,
        ];
        file_put_contents($this->testsWorkingDir . '/composer.json', json_encode($composerJsonArray));

        exec('composer install --no-dev');

        /**
         * @see DependenciesCommand::execute()
         */
        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);
    }
}
