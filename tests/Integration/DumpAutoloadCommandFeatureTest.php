<?php
/**
 * `strauss dump-autoload` regenerates the target directory's autoloader without copying or prefixing again.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss;

/**
 * @covers \BrianHenryIE\Strauss\Console\Commands\DumpAutoloadCommand
 */
class DumpAutoloadCommandFeatureTest extends IntegrationTestCase
{
    /**
     * With `include_root_autoload`, a class added to the project's `classmap` directory after Strauss has run
     * should be added to the `vendor-prefixed` autoloader.
     */
    public function test_adds_new_root_classmap_class(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/testdumpautoloadcommand",
  "require": {
    "psr/log": "1.1.4"
  },
  "autoload": {
    "classmap": [
      "src"
    ]
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Dump_Autoload\\",
      "classmap_prefix": "Dump_Autoload_",
      "include_root_autoload": true,
      "delete_vendor_packages": true
    }
  }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);
        $this->getFileSystem()->write($this->testsWorkingDir . '/src/First.php', '<?php class My_First_Class {}');

        chdir($this->testsWorkingDir);

        exec('composer install', $composerInstallOutput, $composerInstallExitCode);
        $this->assertEquals(0, $composerInstallExitCode, implode(PHP_EOL, $composerInstallOutput));

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $classmapPath = $this->testsWorkingDir . '/vendor-prefixed/composer/autoload_classmap.php';
        $loggerPath = $this->testsWorkingDir . '/vendor-prefixed/psr/log/Psr/Log/LoggerInterface.php';

        $classmapBefore = $this->getFileSystem()->read($classmapPath);
        $this->assertStringContainsString('My_First_Class', $classmapBefore);
        $this->assertStringNotContainsString('My_Second_Class', $classmapBefore);
        $loggerBefore = $this->getFileSystem()->read($loggerPath);

        $this->getFileSystem()->write($this->testsWorkingDir . '/src/Second.php', '<?php class My_Second_Class {}');

        $exitCode = $this->runStrauss($output, 'dump-autoload');
        $this->assertEquals(0, $exitCode, $output);

        $classmapAfter = $this->getFileSystem()->read($classmapPath);
        $this->assertStringContainsString('My_First_Class', $classmapAfter);
        $this->assertStringContainsString('My_Second_Class', $classmapAfter);
        $this->assertStringContainsString('BrianHenryIE\\\\Dump_Autoload\\\\Psr\\\\Log\\\\LoggerInterface', $classmapAfter);

        // The packages' files were not prefixed a second time.
        $this->assertSame($loggerBefore, $this->getFileSystem()->read($loggerPath));

        // Composer's own files are prefixed again after being regenerated.
        $autoloadRealPhpString = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/composer/autoload_real.php');
        $this->assertStringContainsString('BrianHenryIE\\Dump_Autoload\\Composer\\Autoload\\ClassLoader', $autoloadRealPhpString);

        exec(
            'php -r "require \'vendor-prefixed/autoload.php\'; new My_Second_Class(); echo interface_exists(\\BrianHenryIE\\Dump_Autoload\\Psr\\Log\\LoggerInterface::class) ? \'ok\' : \'missing\';" 2>&1',
            $phpOutput,
            $phpExitCode
        );
        $this->assertEquals(0, $phpExitCode, implode(PHP_EOL, $phpOutput));
        $this->assertSame('ok', implode(PHP_EOL, $phpOutput));
    }

    /**
     * Only `composer.json` and the target directory should be needed.
     */
    public function test_works_without_vendor_directory(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/testdumpautoloadcommand",
  "require": {
    "psr/log": "1.1.4"
  },
  "autoload": {
    "classmap": [
      "src"
    ]
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Dump_Autoload\\",
      "classmap_prefix": "Dump_Autoload_",
      "include_root_autoload": true,
      "delete_vendor_packages": true
    }
  }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);
        $this->getFileSystem()->write($this->testsWorkingDir . '/src/First.php', '<?php class My_First_Class {}');

        chdir($this->testsWorkingDir);

        exec('composer install', $composerInstallOutput, $composerInstallExitCode);
        $this->assertEquals(0, $composerInstallExitCode, implode(PHP_EOL, $composerInstallOutput));

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $this->deleteDir($this->testsWorkingDir . '/vendor');
        $this->assertDirectoryDoesNotExist($this->testsWorkingDir . '/vendor');

        $composerDir = $this->testsWorkingDir . '/vendor-prefixed/composer';
        $installedVersionsBefore = $this->getFileSystem()->read($composerDir . '/InstalledVersions.php');
        $installedPhpBefore = $this->getFileSystem()->read($composerDir . '/installed.php');

        $this->getFileSystem()->write($this->testsWorkingDir . '/src/Second.php', '<?php class My_Second_Class {}');

        $exitCode = $this->runStrauss($output, 'dump-autoload');
        $this->assertEquals(0, $exitCode, $output);

        $this->assertDirectoryDoesNotExist($this->testsWorkingDir . '/vendor');

        $classmap = $this->getFileSystem()->read($composerDir . '/autoload_classmap.php');
        $this->assertStringContainsString('My_Second_Class', $classmap);
        $this->assertStringContainsString("'BrianHenryIE\\\\Dump_Autoload\\\\Composer\\\\InstalledVersions'", $classmap);
        $this->assertStringNotContainsString("'Composer\\\\InstalledVersions'", $classmap);

        $static = $this->getFileSystem()->read($composerDir . '/autoload_static.php');
        $this->assertStringNotContainsString("'Composer\\\\InstalledVersions'", $static);

        // The existing, already prefixed, files are kept as they were.
        $this->assertSame($installedVersionsBefore, $this->getFileSystem()->read($composerDir . '/InstalledVersions.php'));
        $this->assertSame($installedPhpBefore, $this->getFileSystem()->read($composerDir . '/installed.php'));

        exec(
            'php -r "require \'vendor-prefixed/autoload.php\'; new My_Second_Class(); echo interface_exists(\\BrianHenryIE\\Dump_Autoload\\Psr\\Log\\LoggerInterface::class) && class_exists(\\BrianHenryIE\\Dump_Autoload\\Composer\\InstalledVersions::class) ? \'ok\' : \'missing\';" 2>&1',
            $phpOutput,
            $phpExitCode
        );
        $this->assertEquals(0, $phpExitCode, implode(PHP_EOL, $phpOutput));
        $this->assertSame('ok', implode(PHP_EOL, $phpOutput));
    }
}
