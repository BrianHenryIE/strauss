<?php
/**
 * When the autoloader is regenerated without a `vendor` directory, Composer's `InstalledVersions.php` in the target
 * directory is the already prefixed copy from the earlier run.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Pipeline\Prefixer;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\Prefixer
 */
class PrefixerComposerRuntimeFilesIntegrationTest extends IntegrationTestCase
{
    protected function getSut(): Prefixer
    {
        $config = new StraussConfig();
        $config->setNamespacePrefix('Prefix');

        return new Prefixer($config, $this->getFileSystem(), new NullLogger());
    }

    protected function writeComposerFiles(string $installedVersionsNamespace): string
    {
        $composerDir = $this->testsWorkingDir . '/vendor-prefixed/composer';

        $this->getFileSystem()->write(
            $composerDir . '/InstalledVersions.php',
            "<?php\n\nnamespace {$installedVersionsNamespace};\n\n/**\n * \\{$installedVersionsNamespace}\\InstalledVersions::satisfies()\n */\nclass InstalledVersions\n{\n}\n"
        );
        $this->getFileSystem()->write(
            $composerDir . '/ClassLoader.php',
            "<?php\n\nnamespace Composer\\Autoload;\n\nclass ClassLoader\n{\n}\n"
        );
        $this->getFileSystem()->write(
            $composerDir . '/autoload_classmap.php',
            "<?php\n\nreturn array(\n    'Composer\\\\InstalledVersions' => __DIR__ . '/InstalledVersions.php',\n);\n"
        );

        return $composerDir;
    }

    /**
     * @covers ::getComposerRuntimeSymbols
     */
    public function test_original_name_is_registered_for_already_prefixed_class(): void
    {
        $composerDir = $this->writeComposerFiles('Prefix\\Composer');

        $sut = $this->getSut();

        $symbols = $sut->getComposerRuntimeSymbols($sut->getComposerRuntimeFiles($composerDir));

        $originalClass = $symbols->getClass('Composer\\InstalledVersions');
        $this->assertNotNull($originalClass);
        $this->assertTrue($originalClass->isDoRename());

        $originalNamespace = $symbols->getNamespace('Composer');
        $this->assertNotNull($originalNamespace);
        $this->assertSame('Prefix\\Composer', $originalNamespace->getLocalReplacement());
    }

    /**
     * @covers ::prefixComposerAutoloadFiles
     * @covers ::isNamespaceAlreadyPrefixed
     */
    public function test_already_prefixed_file_is_not_prefixed_again(): void
    {
        $composerDir = $this->writeComposerFiles('Prefix\\Composer');
        $installedVersionsBefore = $this->getFileSystem()->read($composerDir . '/InstalledVersions.php');

        $this->getSut()->prefixComposerAutoloadFiles($this->testsWorkingDir . '/vendor-prefixed');

        $this->assertSame($installedVersionsBefore, $this->getFileSystem()->read($composerDir . '/InstalledVersions.php'));

        $classmap = $this->getFileSystem()->read($composerDir . '/autoload_classmap.php');
        $this->assertStringContainsString("'Prefix\\\\Composer\\\\InstalledVersions'", $classmap);

        $classLoader = $this->getFileSystem()->read($composerDir . '/ClassLoader.php');
        $this->assertStringContainsString('namespace Prefix\\Composer\\Autoload;', $classLoader);
    }

    /**
     * @covers ::prefixComposerAutoloadFiles
     * @covers ::isNamespaceAlreadyPrefixed
     */
    public function test_unprefixed_file_is_prefixed(): void
    {
        $composerDir = $this->writeComposerFiles('Composer');

        $this->getSut()->prefixComposerAutoloadFiles($this->testsWorkingDir . '/vendor-prefixed');

        $installedVersions = $this->getFileSystem()->read($composerDir . '/InstalledVersions.php');
        $this->assertStringContainsString('namespace Prefix\\Composer;', $installedVersions);
        $this->assertStringNotContainsString('Prefix\\Prefix', $installedVersions);

        $classmap = $this->getFileSystem()->read($composerDir . '/autoload_classmap.php');
        $this->assertStringContainsString("'Prefix\\\\Composer\\\\InstalledVersions'", $classmap);
    }
}
