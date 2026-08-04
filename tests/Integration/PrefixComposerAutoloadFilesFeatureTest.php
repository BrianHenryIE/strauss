<?php
/**
 * @see \BrianHenryIE\Strauss\Pipeline\Prefixer::prefixComposerAutoloadFiles()
 */

namespace BrianHenryIE\Strauss;

/**
 * @coversNothing
 */
class PrefixComposerAutoloadFilesFeatureTest extends IntegrationTestCase
{

    public function test_correct_renaming_in_composer_autoloader_files(): void
    {
        $this->markTestSkippedOnPhpVersionBelow('8.0.0');

        $composerJsonString = <<<'EOD'
{
  "name": "strauss/issue183",
  "require": {
    "psr/log": "2.0.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Strauss\\Prefixed\\",
      "delete_vendor_packages": true
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        // php -r "include __DIR__ . '/vendor-prefixed/autoload.php'; new class() { use Strauss\Issue183\Psr\Log\LoggerAwareTrait; };"
        exec('php -r "include __DIR__ . \'/vendor-prefixed/autoload.php\'; new class() { use Strauss\Prefixed\Psr\Log\LoggerAwareTrait; };" 2>&1', $output, $result_code);
        $outputString = implode(PHP_EOL, $output);
        $this->assertEquals(0, $result_code, $outputString);

        /**
         * autoload_real.php
         *
         * if ('Composer\Autoload\ClassLoader' === $class) {
         * call_user_func(\Composer\Autoload\ComposerStaticInit
         */
        $autoloadRealPhpString = $this->getFileSystem()->read($this->testsWorkingDir .'/vendor-prefixed/composer/autoload_real.php');
        $this->assertStringNotContainsString("('Composer\Autoload\ClassLoader' === \$class)", $autoloadRealPhpString);
        $this->assertStringContainsString('Strauss\Prefixed\Composer\Autoload\ClassLoader', $autoloadRealPhpString);
        $this->assertStringNotContainsString("call_user_func(\Composer\Autoload\ComposerStaticInit", $autoloadRealPhpString);
        $this->assertStringContainsString("call_user_func(\Strauss\Prefixed\Composer\Autoload\ComposerStaticInit", $autoloadRealPhpString);

        /**
         * autoload_static.php
         *
         * namespace Composer\Autoload;
         */
        $autoloadStaticPhpString = file_get_contents($this->testsWorkingDir .'/vendor-prefixed/composer/autoload_static.php');
        $this->assertStringNotContainsString('namespace Composer\Autoload;', $autoloadStaticPhpString);
        $this->assertStringContainsString('namespace Strauss\Prefixed\Composer\Autoload;', $autoloadStaticPhpString);

        /**
         * ClassLoader.php
         *
         * namespace Composer\Autoload;
         */
        $classLoaderPhpString = file_get_contents($this->testsWorkingDir .'/vendor-prefixed/composer/ClassLoader.php');
        $this->assertStringNotContainsString('namespace Composer\Autoload;', $classLoaderPhpString);
        $this->assertStringContainsString('namespace Strauss\Prefixed\Composer\Autoload;', $classLoaderPhpString);

        /**
         * InstalledVersions.php
         *
         * namespace Composer\Autoload;
         */
        $installedVersionsPhpString = file_get_contents($this->testsWorkingDir .'/vendor-prefixed/composer/InstalledVersions.php');
        $this->assertStringNotContainsString('namespace Composer;', $installedVersionsPhpString);
        $this->assertStringContainsString('namespace Strauss\Prefixed\Composer;', $installedVersionsPhpString);
    }
}
