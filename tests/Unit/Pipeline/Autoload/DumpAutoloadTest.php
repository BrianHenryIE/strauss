<?php

namespace BrianHenryIE\Strauss\Pipeline\Autoload;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\Strauss\Config\AutoloadConfigInterface;
use BrianHenryIE\Strauss\Config\FileEnumeratorConfig;
use BrianHenryIE\Strauss\Config\PrefixerConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Pipeline\FileEnumerator;
use BrianHenryIE\Strauss\Pipeline\Prefixer;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use Mockery;
use Psr\Log\NullLogger;

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
        $this->markTestSkipped('Could not read project/composer.json; probably needs the Composer PR completed');

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
        $config->expects('getNamespacePrefix')->times(8)->andReturn('BrianHenryIE\\Test\\');

        $config->expects('getVendorDirectory')->times(2)->andReturn('project/vendor/');
        $config->expects('getExcludeNamespacesFromCopy')->times(2)->andReturn([]);
        $config->expects('getExcludePackagesFromCopy')->times(2)->andReturn([]);
        $config->expects('getExcludeFilePatternsFromCopy')->times(2)->andReturn([]);
        $config->expects('getClassmapPrefix')->times(6)->andReturn('BrianHenryIE_Test_');
        $config->expects('getConstantsPrefix')->times(18)->andReturn('BRIANHENRYIE_TEST_');
        $config->expects('getExcludeNamespacesFromPrefixing')->times(6)->andReturn([]);

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

        $prefixer = Mockery::mock(Prefixer::class);
        $fileEnumerator = Mockery::mock(FileEnumerator::class);

        $sut = new DumpAutoload($config, $filesystem, $logger, $prefixer, $fileEnumerator);

        $sut->generatedPrefixedAutoloader();

        $this->expectNotToPerformAssertions();
    }

    /**
     * @covers ::__construct
     * @covers ::createInstalledVersionsFiles
     */
    public function test_create_installed_versions_files(): void
    {
        $config = Mockery::mock(
            AutoloadConfigInterface::class,
            PrefixerConfigInterface::class,
            FileEnumeratorConfig::class
        );
        $filesystem = $this->getInMemoryFileSystem();
//      $logger = new ColorLogger();
        $logger = new NullLogger();

        $config->expects('isDryRun')->times(1)->andReturn(true);
        $config->expects('getVendorDirectory')->times(3)->andReturn('mem://project/vendor');
        $config->expects('getTargetDirectory')->times(4)->andReturn('mem://project/vendor-prefixed');

        $installedVersions = <<<EOD
<?php // a core Composer file that is not unique per install.
EOD;
        $filesystem->write('project/vendor/composer/InstalledVersions.php', $installedVersions);

        $installedPhp = <<<EOD
<?php return array(
    'root' => array(
        'name' => '__root__',
        'pretty_version' => 'dev-master',
        'version' => 'dev-master',
        'reference' => '6a42cdc603bb428cdc5eaa6ff088d7484d291537',
        'type' => 'library',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        '__root__' => array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'reference' => '6a42cdc603bb428cdc5eaa6ff088d7484d291537',
            'type' => 'library',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'duck7000/imdb-graphql-php' => array(
            'pretty_version' => 'dev-jcv',
            'version' => 'dev-jcv',
            'reference' => 'cfdc4a753dc61f1ffb0a3c742553f0bc83ffc687',
            'type' => 'library',
            'install_path' => __DIR__ . '/../duck7000/imdb-graphql-php',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'monolog/monolog' => array(
            'pretty_version' => '2.10.0',
            'version' => '2.10.0.0',
            'reference' => '5cf826f2991858b54d5c3809bee745560a1042a7',
            'type' => 'library',
            'install_path' => __DIR__ . '/../monolog/monolog',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'psr/log' => array(
            'pretty_version' => '1.1.0',
            'version' => '1.1.0.0',
            'reference' => '6c001f1daafa3a3ac1d8ff69ee4db8e799a654dd',
            'type' => 'library',
            'install_path' => __DIR__ . '/../psr/log',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'psr/log-implementation' => array(
            'dev_requirement' => false,
            'provided' => array(
                0 => '1.0.0 || 2.0.0 || 3.0.0',
            ),
        ),
        'psr/simple-cache' => array(
            'pretty_version' => '1.0.1',
            'version' => '1.0.1.0',
            'reference' => '408d5eafb83c57f6365a3ca330ff23aa4a5fa39b',
            'type' => 'library',
            'install_path' => __DIR__ . '/../psr/simple-cache',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'twbs/bootstrap' => array(
            'pretty_version' => 'v5.3.6',
            'version' => '5.3.6.0',
            'reference' => 'f849680d16a9695c9a6c9c062d6cff55ddcf071e',
            'type' => 'library',
            'install_path' => __DIR__ . '/../twbs/bootstrap',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'twitter/bootstrap' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => 'v5.3.6',
            ),
        ),
    ),
);
EOD;
        $filesystem->write('project/vendor/composer/installed.php', $installedPhp);

        $packagesToCopy = [
            'duck7000/imdb-graphql-php' => array(),
            'monolog/monolog' => array(),
        ];
        $config->expects('getPackagesToCopy')->once()->andReturn($packagesToCopy);

        $projectReplace = Mockery::mock(Prefixer::class);
        $fileEnumerator = Mockery::mock(FileEnumerator::class);
        $fileEnumerator->expects('compileFileListForPaths')->once()->andReturn(new DiscoveredFiles());
        $config->expects('getNamespacePrefix')->times(2)->andReturn('DumpAutoload\\');
        $projectReplace->expects('replaceInProjectFiles')->once();
        $dumpAutoload = new DumpAutoload(
            $config,
            $filesystem,
            $logger,
            $projectReplace,
            $fileEnumerator
        );
        $dumpAutoload->generatedPrefixedAutoloader();

        $result = $filesystem->read('project/vendor-prefixed/composer/installed.php');

        $this->assertStringContainsString('=> __DIR__', $result);
    }
}
