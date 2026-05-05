<?php

namespace BrianHenryIE\Strauss\Composer;

use Composer\Factory;
use Composer\IO\NullIO;
use BrianHenryIE\Strauss\TestCase;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Composer\ComposerPackage
 */
class ComposerPackageTest extends TestCase
{

    /**
     * A simple test to check the getters all work.
     */
    public function testParseJson(): void
    {

        $testFile = __DIR__ . '/composerpackage-test-libmergepdf.json';

        $composer = ComposerPackage::fromFile($testFile);

        $this->assertEqualsRN('iio/libmergepdf', $composer->getPackageName());

        $this->assertIsArray($composer->getAutoload());

        $this->assertIsArray($composer->getRequiresNames());
    }

    /**
     * Test the dependencies' names are returned.
     */
    public function testGetRequiresNames(): void
    {

        $testFile = __DIR__ . '/composerpackage-test-libmergepdf.json';

        $composer = ComposerPackage::fromFile($testFile);

        $requiresNames = $composer->getRequiresNames();

        $this->assertContains('tecnickcom/tcpdf', $requiresNames);
        $this->assertContains('setasign/fpdi', $requiresNames);
    }

    /**
     * Test PHP and ext- are not returned, since we won't be dealing with them.
     */
    public function testGetRequiresNamesDoesNotContain(): void
    {

        $testFile = __DIR__ . '/composerpackage-test-easypost-php.json';

        $composer = ComposerPackage::fromFile($testFile);

        $requiresNames = $composer->getRequiresNames();

        $this->assertNotContains('ext-curl', $requiresNames);
        $this->assertNotContains('php', $requiresNames);
    }


    /**
     *
     */
    public function testAutoloadPsr0(): void
    {

        $testFile = __DIR__ . '/composerpackage-test-easypost-php.json';

        $composer = ComposerPackage::fromFile($testFile);

        $autoload = $composer->getAutoload();

        $this->assertArrayHasKey('psr-0', $autoload);

        $this->assertIsArray($autoload['psr-0']);
    }

    /**
     *
     */
    public function testAutoloadPsr4(): void
    {

        $testFile = __DIR__ . '/composerpackage-test-libmergepdf.json';

        $composer = ComposerPackage::fromFile($testFile);

        $autoload = $composer->getAutoload();

        $this->assertArrayHasKey('psr-4', $autoload);

        $this->assertIsArray($autoload['psr-4']);
    }

    /**
     *
     */
    public function testAutoloadClassmap(): void
    {

        $testFile = __DIR__ . '/composerpackage-test-libmergepdf.json';

        $composer = ComposerPackage::fromFile($testFile);

        $autoload = $composer->getAutoload();

        $this->assertArrayHasKey('classmap', $autoload);

        $this->assertIsArray($autoload['classmap']);
    }

    /**
     *
     */
    public function testAutoloadFiles(): void
    {

        $testFile = __DIR__ . '/composerpackage-test-php-di.json';

        $composer = ComposerPackage::fromFile($testFile);

        $autoload = $composer->getAutoload();

        $this->assertArrayHasKey('files', $autoload);

        $this->assertIsArray($autoload['files']);
    }

    public function testPsr4Array(): void
    {

        $composerJson = <<<'EOD'
{
    "autoload": {
        "psr-4": { "Monolog\\": ["src/", "lib/"] }
    }
}

EOD;
        $projectDir = 'project';
        $composerJsonPath = $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new ComposerPackage($composer);

        $autoload = $sut->getAutoload();

        $this->assertArrayHasKey('psr-4', $autoload);

        $psr4Autoload = $autoload['psr-4'];

        $this->assertArrayHasKey('Monolog\\', $psr4Autoload);

        $monologAutoload = $psr4Autoload['Monolog\\'];

        $this->assertContains('src/', $monologAutoload);
        $this->assertContains('lib/', $monologAutoload);
    }

    public function testOverrideAutoload(): void
    {
        $this->markTestIncomplete();
    }

    /**
     * When composer.json is not where it was specified, what error message (via Exception) should be returned?
     */
    public function testMissingComposer(): void
    {
        $this->markTestIncomplete();
    }

    /**
     * @covers ::isCopy
     * @covers ::setCopy
     */
    public function test_is_copy(): void
    {
        $testFile = __DIR__ . '/composerpackage-test-libmergepdf.json';
        $sut = ComposerPackage::fromFile($testFile);

        // Default is `true`.
        $this->assertTrue($sut->isCopy());

        $sut->setCopy(false);

        $this->assertFalse($sut->isCopy());
    }

    /**
     * @covers ::didCopy
     * @covers ::setDidCopy
     */
    public function test_did_copy(): void
    {
        $testFile = __DIR__ . '/composerpackage-test-libmergepdf.json';
        $sut = ComposerPackage::fromFile($testFile);

        // Default is `false`.
        $this->assertFalse($sut->didCopy());

        $sut->setDidCopy(true);

        $this->assertTrue($sut->didCopy());
    }

    /**
     * @covers ::isDoDelete
     * @covers ::setDelete
     */
    public function test_is_delete(): void
    {
        $testFile = __DIR__ . '/composerpackage-test-libmergepdf.json';
        $sut = ComposerPackage::fromFile($testFile);

        // Default is `false`.
        $this->assertFalse($sut->isDoDelete());

        $sut->setDelete(true);

        $this->assertTrue($sut->isDoDelete());
    }

    /**
     * @covers ::didDelete
     * @covers ::setDidDelete
     */
    public function test_did_delete(): void
    {
        $testFile = __DIR__ . '/composerpackage-test-libmergepdf.json';
        $sut = ComposerPackage::fromFile($testFile);

        // Default is `false`.
        $this->assertFalse($sut->didDelete());

        $sut->setDidDelete(true);

        $this->assertTrue($sut->didDelete());
    }

    /**
     * Verify getPackageAbsolutePath() contains no backslashes.
     *
     * On Windows: realpath() returns backslashes, fix normalizes them. Test FAILS before fix, PASSES after.
     * On Linux: realpath() returns forward slashes already. Test PASSES (no regression).
     *
     * @covers ::getPackageAbsolutePath
     */
    public function testGetPackageAbsolutePathHasNoBackslashes(): void
    {
        $testFile = __DIR__ . '/composerpackage-test-libmergepdf.json';
        $sut = ComposerPackage::fromFile($testFile);

        $absolutePath = $sut->getPackageAbsolutePath();

        $this->assertStringNotContainsString('\\', $absolutePath ?? '');
    }
}
