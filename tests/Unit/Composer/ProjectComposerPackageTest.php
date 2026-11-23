<?php

namespace BrianHenryIE\Strauss\Composer;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\TestCase;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Composer\ProjectComposerPackage
 */
class ProjectComposerPackageTest extends TestCase
{
    /**
     * A simple test to check the getters all work.
     */
    public function testParseJson(): void
    {
        $this->getFileSystem()->write(
            'project/composer.json',
            $this->getFixturesFilesystem()->read(__DIR__ . '/projectcomposerpackage-test-1.json')
        );

        $composer = new ProjectComposerPackage(
            $this->getFileSystem()->makeAbsolute('mem://project/composer.json')
        );

        $config = $composer->getStraussConfig();

        $this->assertInstanceOf(StraussConfig::class, $config);
    }

    /**
     * @covers ::getFlatAutoloadKey
     */
    public function testGetFlatAutoloadKey(): void
    {
        $this->getFileSystem()->write(
            'project/composer.json',
            $this->getFixturesFilesystem()->read(
                __DIR__ . '/projectcomposerpackage-test-getProjectPhpFiles.json'
            )
        );

        $composer = new ProjectComposerPackage(
            $this->getFileSystem()->makeAbsolute('mem://project/composer.json')
        );

        $phpFiles = $composer->getFlatAutoloadKey();

        $expected = ["src","includes","classes","functions.php"];

        $this->assertEquals($expected, $phpFiles);
    }
    /**
     * Pins what Strauss reads from the project's `Composer` object, so how that object is created can change.
     *
     * @covers ::__construct
     * @covers ::getAuthor
     * @covers ::getRelativeVendorDirectory
     * @covers ::getStraussConfig
     */
    public function testValuesReadFromProjectComposerJson(): void
    {
        $this->getFileSystem()->write(
            'project/composer.json',
            (string) json_encode([
                'name' => 'brianhenryie/strauss-config-test',
                'authors' => [
                    ['name' => 'Brian Henry', 'email' => 'brian@example.org'],
                ],
                'license' => 'MIT',
                'require' => [
                    'league/container' => '*',
                ],
                'suggest' => [
                    'psr/log' => 'For logging',
                ],
                'autoload' => [
                    'psr-4' => [
                        'BrianHenryIE\\TestStrauss\\' => 'src',
                    ],
                ],
                'config' => [
                    'vendor-dir' => 'libraries',
                    'autoloader-suffix' => 'TestSuffix',
                ],
                'extra' => [
                    'strauss' => [
                        'namespace_prefix' => 'BrianHenryIE\\Prefixed\\',
                        'target_directory' => 'prefixed',
                    ],
                ],
            ])
        );

        $sut = new ProjectComposerPackage(
            $this->getFileSystem()->makeAbsolute('mem://project/composer.json')
        );

        $this->assertSame('brianhenryie/strauss-config-test', $sut->getPackageName());
        $this->assertSame('Brian Henry', $sut->getAuthor());
        $this->assertSame('MIT', $sut->getLicense());
        $this->assertSame('libraries', $sut->getRelativeVendorDirectory());
        $this->assertSame(['league/container'], $sut->getRequiresNames());
        $this->assertSame(['src'], $sut->getFlatAutoloadKey());

        $config = $sut->getStraussConfig();

        $this->assertSame('BrianHenryIE\\Prefixed', $config->getNamespacePrefix());
        $this->assertStringEndsWith('project/prefixed', rtrim($config->getAbsoluteTargetDirectory(), '/'));
        $this->assertStringEndsWith('project/libraries', rtrim($config->getAbsoluteVendorDirectory(), '/'));
    }

    /**
     * Reading the project's composer.json must not have side effects. A full `Composer` load dispatches Composer's
     * `init` event, which runs a script of that name.
     *
     * @covers ::__construct
     */
    public function testConstructingDoesNotRunComposerScripts(): void
    {
        $markerFile = sys_get_temp_dir() . '/' . uniqid('strauss-init-script-ran-', true);

        $this->getFileSystem()->write(
            'project/composer.json',
            (string) json_encode([
                'name' => 'brianhenryie/strauss-config-test',
                'scripts' => [
                    'init' => sprintf('%s -r %s', escapeshellarg(PHP_BINARY), escapeshellarg("touch('{$markerFile}');")),
                ],
            ])
        );

        new ProjectComposerPackage(
            $this->getFileSystem()->makeAbsolute('mem://project/composer.json')
        );

        $scriptRan = file_exists($markerFile);
        if ($scriptRan) {
            unlink($markerFile);
        }

        $this->assertFalse($scriptRan, 'The project\'s `init` Composer script was run.');
    }
}
