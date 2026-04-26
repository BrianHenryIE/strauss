<?php

namespace BrianHenryIE\Strauss\Autoload;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\Autoload\Psr0
 */
class Psr0FeatureTest extends IntegrationTestCase
{

    public function test_adds_updated_directory_structure(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "pimple/pimple": "3.6.2"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\"
    }
  }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install', $composerInstallOutput, $composerInstallExitCode);
        $this->assertEquals(0, $composerInstallExitCode, implode(PHP_EOL, $composerInstallOutput));

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        // vendor/pimple/pimple/src/Pimple/Container.php
        // vendor-prefixed/pimple/pimple/src/BrianHenryIE/Strauss/Pimple/Container.php
        $this->assertTrue($this->getFileSystem()->fileExists($this->testsWorkingDir . '/vendor-prefixed/pimple/pimple/src/BrianHenryIE/Strauss/Pimple/Container.php'));

        $installedJson = json_decode($this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/composer/installed.json'), true);
        $this->assertEquals('BrianHenryIE\Strauss\Pimple', array_key_first($installedJson['packages'][0]['autoload']['psr-0']));

        exec('php -r "include __DIR__ . \'/vendor-prefixed/autoload.php\'; new \BrianHenryIE\Strauss\Pimple\Container();" 2>&1', $output, $result_code);
        $outputString = implode(PHP_EOL, $output);

        $this->assertEquals(0, $result_code, $outputString);
    }
}
