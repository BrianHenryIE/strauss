<?php

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Pipeline\Autoload;
use BrianHenryIE\Strauss\Pipeline\Cleanup\Cleanup;
use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @coversNothing
 */
class DryRunFeatureTest extends IntegrationTestCase
{
    /**
     * Test default config is false.
     *
     * TODO: This should be in a unit test.
     */
    public function test_not_enabled(): void
    {
        $config = new StraussConfig();

        $this->assertFalse($config->isDryRun());
    }

    protected function getDirectoryMd5s(string $directory): array
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );

        $hashes = [];

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isFile()) {
                $hashes[$file->getPath()] = md5_file($file->getPathname());
            }
        }

        return [md5(implode('', $hashes)), $hashes];
    }

    protected function assertEqualsDirectoryHashes(array $hashesBefore, array $hashesAfter): void
    {
        if ($hashesBefore[0] === $hashesAfter[0]) {
            // Pass test!
            return;
        }

        $diff = array_merge(
            array_diff_assoc(array_keys($hashesBefore[1]), array_keys($hashesAfter[1])),
            array_diff_assoc(array_keys($hashesAfter[1]), array_keys($hashesBefore[1]))
        );

        $this->fail('Hashes do not match. Files changed: ' . implode(', ', $diff));
    }

    /**
     * Test using composer.json config disables changes and outputs to console.
     */
    public function test_happy_path(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "league/container": "4.2.4"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_",
      "delete_vendor_files": true,
      "dry_run": true
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $hashesBefore = $this->getDirectoryMd5s($this->testsWorkingDir);

        $exitCode = $this->runStrauss($output);
        assert($exitCode === 0, $output);

        $this->assertFileExists($this->testsWorkingDir . 'vendor/league/container/src/Container.php');
        $this->assertFileDoesNotExist($this->testsWorkingDir . 'vendor-prefixed/league/container/src/Container.php');

        $hashesAfter = $this->getDirectoryMd5s($this->testsWorkingDir);
        $this->assertEqualsDirectoryHashes($hashesBefore, $hashesAfter);
    }

    /**
     * Test CLI argument --dry-run disables changes and outputs to console.
     */
    public function test_cli_argument(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "league/container": "4.2.4"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_",
      "delete_vendor_files": true
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $params = '--dry-run';

        $hashesBefore = $this->getDirectoryMd5s($this->testsWorkingDir);

        $this->runStrauss($output, $params);

        $this->assertFileExists($this->testsWorkingDir . 'vendor/league/container/src/Container.php');
        $this->assertFileDoesNotExist($this->testsWorkingDir . 'vendor-prefixed/league/container/src/Container.php');

        $hashesAfter = $this->getDirectoryMd5s($this->testsWorkingDir);
        $this->assertEqualsDirectoryHashes($hashesBefore, $hashesAfter);
    }

    /**
     * Test CLI argument overrides composer.json config.
     */
    public function test_cli_argument_overrides_composer_json(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "league/container": "4.2.4"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_",
      "delete_vendor_files": true,
      "dry_run": true
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $params = '--dry-run=false';

        $this->runStrauss($output, $params);

        $this->assertStringNotContainsString('Would copy', $output);

        $this->assertFileExists($this->testsWorkingDir . 'vendor-prefixed/league/container/src/Container.php');
    }

    /**
     *
     *
     * @see Autoload::generateClassmap()
     */
    public function testGenerateAutoload():void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "league/container": "4.2.4"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_",
      "delete_vendor_packages": true,
      "dry_run": true
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $hashesBefore = $this->getDirectoryMd5s($this->testsWorkingDir);

        $exitCode = $this->runStrauss($output);
        assert($exitCode === 0, $output);

        $this->assertFileDoesNotExist($this->testsWorkingDir . 'vendor-prefixed/autoload.php');

        $hashesAfter = $this->getDirectoryMd5s($this->testsWorkingDir);
        $this->assertEqualsDirectoryHashes($hashesBefore, $hashesAfter);
    }

    /**
     * Composer
     *
     * @see Cleanup\InstalledJson::cleanupVendorInstalledJson()
     */
    public function test_composer_files_not_modified(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "league/container": "4.2.4"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_",
      "delete_vendor_packages": true,
      "dry_run": true
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $expected = file_get_contents($this->testsWorkingDir . 'vendor/composer/installed.json');

        $hashesBefore = $this->getDirectoryMd5s($this->testsWorkingDir);

        $exitCode = $this->runStrauss($output);
        assert($exitCode === 0, $output);

        $this->assertEquals(
            $expected,
            file_get_contents(
                $this->testsWorkingDir . 'vendor/composer/installed.json'
            )
        );

        $hashesAfter = $this->getDirectoryMd5s($this->testsWorkingDir);
        $this->assertEqualsDirectoryHashes($hashesBefore, $hashesAfter);

        $this->assertDirectoryDoesNotExist($this->testsWorkingDir . 'vendor-prefixed');
    }
}
