<?php

namespace BrianHenryIE\Strauss\Pipeline\Autoload;

use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Config\AutoloadConfigInterface;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Pipeline\FileEnumerator;
use BrianHenryIE\Strauss\Pipeline\Prefixer;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Composer\Autoload\AutoloadGenerator;
use Composer\Config;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Json\JsonFile;
use Composer\Repository\InstalledFilesystemRepository;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class DumpAutoload
{
    use LoggerAwareTrait;

    protected AutoloadConfigInterface $config;

    protected FileSystem $filesystem;
    private Prefixer $projectReplace;
    private FileEnumerator $fileEnumerator;

    public function __construct(
        AutoloadConfigInterface $config,
        Filesystem $filesystem,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->setLogger($logger ?? new NullLogger());

        $this->projectReplace = new Prefixer(
            $this->config,
            $this->filesystem,
            $this->logger
        );

        $this->fileEnumerator = new FileEnumerator(
            $this->config,
            $this->filesystem
        );
    }

    /**
     * Uses `vendor/composer/installed.json` to output autoload files to `vendor-prefixed/composer`.
     */
    public function generatedPrefixedAutoloader(): void
    {
        /**
         * Unfortunately, `::dump()` creates the target directories if they don't exist, even though it otherwise respects `::setDryRun()`.
         */
        if ($this->config->isDryRun()) {
            return;
        }

        $relativeTargetDir = $this->filesystem->getRelativePath(
            $this->config->getProjectDirectory(),
            $this->config->getTargetDirectory()
        );

        $defaultVendorDirBefore = Config::$defaultConfig['vendor-dir'];

        Config::$defaultConfig['vendor-dir'] = $relativeTargetDir;

        $composer = Factory::create(new NullIO(), $this->filesystem->prefixPath($this->config->getProjectDirectory() . 'composer.json'));
        $installationManager = $composer->getInstallationManager();
        $package = $composer->getPackage();

        $projectComposerJson = new JsonFile($this->filesystem->prefixPath($this->config->getProjectDirectory() . 'composer.json'));
        $projectComposerJsonArray = $projectComposerJson->read();
        if (isset($projectComposerJsonArray['config'], $projectComposerJsonArray['config']['vendor-dir'])) {
            unset($projectComposerJsonArray['config']['vendor-dir']);
        }

        /**
         * Cannot use `$composer->getConfig()`, need to create a new one so the vendor-dir is correct.
         */
        $config = new \Composer\Config(
            false,
            $this->filesystem->prefixPath(
                $this->config->getProjectDirectory()
            )
        );

        $config->merge([
            'config' => $projectComposerJsonArray['config'] ?? []
        ]);

        $generator = new ComposerAutoloadGenerator(
            $this->config->getNamespacePrefix(),
            $composer->getEventDispatcher()
        );

        $generator->setDryRun($this->config->isDryRun());
        $generator->setClassMapAuthoritative(true);
        $generator->setRunScripts(false);
//        $generator->setApcu($apcu, $apcuPrefix);
//        $generator->setPlatformRequirementFilter($this->getPlatformRequirementFilter($input));
        $optimize = true; // $input->getOption('optimize') || $config->get('optimize-autoloader');
        $generator->setDevMode(false);

        $localRepo = new InstalledFilesystemRepository(
            new JsonFile(
                $this->filesystem->prefixPath(
                    $this->config->getTargetDirectory() . 'composer/installed.json'
                )
            )
        );

        $strictAmbiguous = false; // $input->getOption('strict-ambiguous')

        // This will output the autoload_static.php etc. files to `vendor-prefixed/composer`.
        $generator->dump(
            $config,
            $localRepo,
            $package,
            $installationManager,
            'composer',
            $optimize,
            $this->getSuffix(),
            $composer->getLocker(),
            $strictAmbiguous
        );

        /**
         * Tests fail if this is absent.
         *
         * Arguably this should be in ::setUp() and tearDown() in the test classes, but if other tools run after Strauss
         * then they might expect it to be unmodified.
         */
        Config::$defaultConfig['vendor-dir'] = $defaultVendorDirBefore;

        $this->prefixNewAutoloader();
    }

    protected function prefixNewAutoloader(): void
    {
        $this->logger->debug('Prefixing the new Composer autoloader.');

        $projectReplace = new Prefixer(
            $this->config,
            $this->filesystem,
            $this->logger
        );

        $fileEnumerator = new FileEnumerator(
            $this->config,
            $this->filesystem
        );

        $projectFiles = $fileEnumerator->compileFileListForPaths([
            $this->config->getTargetDirectory() . 'composer',
        ]);

        $phpFiles = array_filter(
            $projectFiles->getFiles(),
            fn($file) => $file->isPhpFile()
        );

        $phpFilesAbsolutePaths = array_map(
            fn($file) => $file->getSourcePath(),
            $phpFiles
        );

        $sourceFile = new File(__DIR__);
        $composerNamespaceSymbol = new NamespaceSymbol(
            'Composer\\Autoload',
            $sourceFile
        );
        $composerNamespaceSymbol->setReplacement(
            $this->config->getNamespacePrefix() . '\\Composer\\Autoload'
        );

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add(
            $composerNamespaceSymbol
        );

        $this->projectReplace->replaceInProjectFiles($discoveredSymbols, $phpFilesAbsolutePaths);
    }

    /**
     * If there is an existing autoloader, it will use the same suffix. If there is not, it pulls the suffix from
     * {Composer::getLocker()} and clashes with the existing autoloader.
     *
     * @see AutoloadGenerator::dump() 412:431
     * @see https://github.com/composer/composer/blob/ae208dc1e182bd45d99fcecb956501da212454a1/src/Composer/Autoload/AutoloadGenerator.php#L429
     */
    protected function getSuffix(): ?string
    {
        return !$this->filesystem->fileExists($this->config->getTargetDirectory() . 'autoload.php')
            ? bin2hex(random_bytes(16))
            : null;
    }
}
