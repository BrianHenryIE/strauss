<?php
/**
 * @see https://www.php-fig.org/psr/psr-0/
 */

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

    /**
     * Underscores in namespaces.
     *
     * "Each _ character in the CLASS NAME is converted to a DIRECTORY_SEPARATOR."
     *
     * @see teststempdir/b25e5c6bf/vendor/ezyang/htmlpurifier/library/HTMLPurifier.auto.php
     */
    public function test_handles_underscores(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "psr/feature0",
  "require": {
    "ezyang/htmlpurifier": "4.19.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "Global_Prefix_"
    }
  },
  "config": {
    "platform": {
        "php": "7.4"
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

        // vendor/ezyang/htmlpurifier/library/HTMLPurifier/DefinitionCache/Serializer.php
        // vendor-prefixed/ezyang/htmlpurifier/library/BrianHenryIE/Strauss/HTMLPurifier/DefinitionCache/Serializer.php
        $this->assertTrue($this->getFileSystem()->fileExists($this->testsWorkingDir . '/vendor-prefixed/ezyang/htmlpurifier/library/BrianHenryIE/Strauss/HTMLPurifier/DefinitionCache/Serializer.php'));

        $phpString = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/ezyang/htmlpurifier/library/BrianHenryIE/Strauss/HTMLPurifier/DefinitionCache/Serializer.php');
        $this->assertStringContainsString('class BrianHenryIE_Strauss_HTMLPurifier_DefinitionCache_Serializer', $phpString);

        $installedJson = json_decode($this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/composer/installed.json'), true);
        $this->assertEquals('BrianHenryIE_Strauss_HTMLPurifier', array_key_first($installedJson['packages'][0]['autoload']['psr-0']));

        // php -r "include __DIR__ . '/vendor/autoload.php'; new HTMLPurifier_DefinitionCache_Serializer('type');"
        // php -r "include __DIR__ . '/vendor-prefixed/autoload.php'; new BrianHenryIE_Strauss_HTMLPurifier_DefinitionCache_Serializer('type');"
        exec('php -r "include __DIR__ . \'/vendor-prefixed/autoload.php\'; new BrianHenryIE_Strauss_HTMLPurifier_DefinitionCache_Serializer(\'type\');" 2>&1', $output, $result_code);
        $outputString = implode(PHP_EOL, $output);
        $this->assertEquals(0, $result_code, $outputString);
    }
}
