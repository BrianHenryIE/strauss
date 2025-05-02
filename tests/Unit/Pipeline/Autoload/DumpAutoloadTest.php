<?php

namespace BrianHenryIE\Strauss\Pipeline\Autoload;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\Strauss\Config\AutoloadConfigInterface;
use BrianHenryIE\Strauss\Config\FileEnumeratorConfig;
use BrianHenryIE\Strauss\Config\PrefixerConfigInterface;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use Mockery;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\Autoload\DumpAutoload
 */
class DumpAutoloadTest extends \BrianHenryIE\Strauss\TestCase
{
    /**
     * @covers ::generatedPrefixedAutoloader
     */
    public function testGeneratedPrefixedAutoloader():void
    {
        $config = Mockery::mock(
            AutoloadConfigInterface::class,
            PrefixerConfigInterface::class,
            FileEnumeratorConfig::class
        );
        $config->expects('isDryRun')->times(2)->andReturnFalse();
//        $config->expects('getProjectDirectory')->times(3)->andReturn('project/');
        $config->expects('getProjectDirectory')->times(4)->andReturn('project/');
//        $config->expects('getTargetDirectory')->times(2)->andReturn('project/vendor-prefixed/');
        $config->expects('getTargetDirectory')->times(4)->andReturn('project/vendor-prefixed/');
//        $config->expects('getNamespacePrefix')->once()->andReturn('BrianHenryIE\\Test\\');
        $config->expects('getNamespacePrefix')->times(9)->andReturn('BrianHenryIE\\Test\\');

        $config->expects('getVendorDirectory')->once()->andReturn('project/vendor/');
        $config->expects('getExcludeNamespacesFromCopy')->once()->andReturn([]);
        $config->expects('getExcludePackagesFromCopy')->once()->andReturn([]);
        $config->expects('getExcludeFilePatternsFromCopy')->once()->andReturn([]);
        $config->expects('getClassmapPrefix')->times(7)->andReturn('BrianHenryIE_Test_');
        $config->expects('getConstantsPrefix')->times(21)->andReturn('BRIANHENRYIE_TEST_');
        $config->expects('getExcludeNamespacesFromPrefixing')->times(7)->andReturn([]);

        /** @var FileSystem $filesystem */
        $filesystem = $this->getFileSystem();
        $filesystem->createDirectory('project/vendor-prefixed');

        $filesystem->write('project/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [
                    'My\\Namespace\\' => 'src/',
                ],
            ],
        ]));

        $filesystem->write('project/vendor/composer/installed.json', json_encode([
            ]));

        $logger = new ColorLogger();

        $sut = new DumpAutoload($config, $filesystem, $logger);

        $sut->generatedPrefixedAutoloader();

        $this->expectNotToPerformAssertions();
    }
}
