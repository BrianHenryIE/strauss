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
     * @covers ::__construct
     * @covers ::generatedPrefixedAutoloader
     * @covers ::generatedMainAutoloader
     */
    public function testGeneratedPrefixedAutoloader():void
    {
        $config = Mockery::mock(
            AutoloadConfigInterface::class,
            PrefixerConfigInterface::class,
            FileEnumeratorConfig::class
        );
        $config->expects('isDryRun')->atLeast()->once()->andReturnFalse();
        $config->expects('getProjectDirectory')->atLeast()->once()->andReturn('project/');
        $config->expects('getTargetDirectory')->atLeast()->once()->andReturn('project/vendor-prefixed/');
        $config->expects('getNamespacePrefix')->atLeast()->once()->andReturn('BrianHenryIE\\Test\\');

        $config->expects('getVendorDirectory')->atLeast()->once()->andReturn('project/vendor/');

        /** @var FileSystem $filesystem */
        $filesystem = $this->getReadOnlyFileSystem($this->getSymlinkProtectFilesystem());
        $filesystem->createDirectory('project/vendor-prefixed');

        $filesystem->write('project/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [
                    'My\\Namespace\\' => 'src/',
                ],
            ],
        ]));

        $filesystem->write('project/vendor-prefixed/composer/installed.json', json_encode([]));
        $filesystem->write('project/vendor-prefixed/composer/ClassLoader.php', '<?php');

        $prefixer = Mockery::mock(Prefixer::class);

        $projectFiles = Mockery::mock(DiscoveredFiles::class);

        $fileEnumerator = Mockery::mock(FileEnumerator::class);
        $fileEnumerator->expects('compileFileListForPaths')->andReturn($projectFiles);

        $projectFiles->expects('getFiles')->andReturn([]);

        $prefixer->expects('replaceInProjectFiles');

        $composerAutoloadGeneratorFactory = Mockery::mock(ComposerAutoloadGeneratorFactory::class);
        $composerAutoloadGenerator = Mockery::mock(ComposerAutoloadGenerator::class)->makePartial();
        $composerAutoloadGeneratorFactory->expects('get')->once()->andReturn($composerAutoloadGenerator);
        $composerAutoloadGenerator->expects('dump')->once();

        $sut = new DumpAutoload($config, $filesystem, $this->getLogger(), $prefixer, $fileEnumerator, $composerAutoloadGeneratorFactory);

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
        $config->expects('getVendorDirectory')->times(4)->andReturn('mem://project/vendor');
        $config->expects('getTargetDirectory')->times(5)->andReturn('mem://project/vendor-prefixed');

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
        $composerAutoloadGeneratorFactory = Mockery::mock(ComposerAutoloadGeneratorFactory::class);
        $dumpAutoload = new DumpAutoload(
            $config,
            $filesystem,
            $logger,
            $projectReplace,
            $fileEnumerator,
            $composerAutoloadGeneratorFactory
        );
        $dumpAutoload->generatedPrefixedAutoloader();

        $result = $filesystem->read('project/vendor-prefixed/composer/installed.php');

        $this->assertStringContainsString('=> __DIR__', $result);
    }
}
