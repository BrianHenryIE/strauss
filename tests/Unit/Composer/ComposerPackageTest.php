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
    public function testParseJson()
    {

        $testFile = __DIR__ . '/composerpackage-test-libmergepdf.json';

        $composer = ComposerPackage::fromFile($testFile);

        self::assertEqualsRN('iio/libmergepdf', $composer->getPackageName());

        self::assertIsArray($composer->getAutoload());

        self::assertIsArray($composer->getRequiresNames());
    }

    /**
     * Test the dependencies' names are returned.
     */
    public function testGetRequiresNames()
    {

        $testFile = __DIR__ . '/composerpackage-test-libmergepdf.json';

        $composer = ComposerPackage::fromFile($testFile);

        $requiresNames = $composer->getRequiresNames();

        self::assertContains('tecnickcom/tcpdf', $requiresNames);
        self::assertContains('setasign/fpdi', $requiresNames);
    }

    /**
     * Test PHP and ext- are not returned, since we won't be dealing with them.
     */
    public function testGetRequiresNamesDoesNotContain()
    {

        $testFile = __DIR__ . '/composerpackage-test-easypost-php.json';

        $composer = ComposerPackage::fromFile($testFile);

        $requiresNames = $composer->getRequiresNames();

        self::assertNotContains('ext-curl', $requiresNames);
        self::assertNotContains('php', $requiresNames);
    }


    /**
     *
     */
    public function testAutoloadPsr0()
    {

        $testFile = __DIR__ . '/composerpackage-test-easypost-php.json';

        $composer = ComposerPackage::fromFile($testFile);

        $autoload = $composer->getAutoload();

        self::assertArrayHasKey('psr-0', $autoload);

        self::assertIsArray($autoload['psr-0']);
    }

    /**
     *
     */
    public function testAutoloadPsr4()
    {

        $testFile = __DIR__ . '/composerpackage-test-libmergepdf.json';

        $composer = ComposerPackage::fromFile($testFile);

        $autoload = $composer->getAutoload();

        self::assertArrayHasKey('psr-4', $autoload);

        self::assertIsArray($autoload['psr-4']);
    }

    /**
     *
     */
    public function testAutoloadClassmap()
    {

        $testFile = __DIR__ . '/composerpackage-test-libmergepdf.json';

        $composer = ComposerPackage::fromFile($testFile);

        $autoload = $composer->getAutoload();

        self::assertArrayHasKey('classmap', $autoload);

        self::assertIsArray($autoload['classmap']);
    }

    /**
     *
     */
    public function testAutoloadFiles()
    {

        $testFile = __DIR__ . '/composerpackage-test-php-di.json';

        $composer = ComposerPackage::fromFile($testFile);

        $autoload = $composer->getAutoload();

        self::assertArrayHasKey('files', $autoload);

        self::assertIsArray($autoload['files']);
    }

    public function testPsr4Array()
    {

        $composerJson = <<<'EOD'
{
    "autoload": {
        "psr-4": { "Monolog\\": ["src/", "lib/"] }
    }
}

EOD;
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new ComposerPackage($composer);

        $autoload = $sut->getAutoload();

        self::assertArrayHasKey('psr-4', $autoload);

        $psr4Autoload = $autoload['psr-4'];

        self::assertArrayHasKey('Monolog\\', $psr4Autoload);

        $monologAutoload = $psr4Autoload['Monolog\\'];

        self::assertContains('src/', $monologAutoload);
        self::assertContains('lib/', $monologAutoload);
    }

    public function testOverrideAutoload()
    {
        $this->markTestIncomplete();
    }

    /**
     * When composer.json is not where it was specified, what error message (via Exception) should be returned?
     */
    public function testMissingComposer()
    {
        $this->markTestIncomplete();
    }
}
