<?php

namespace BrianHenryIE\Strauss\Pipeline\Autoload;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
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
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Seld\JsonLint\ParsingException;

/**
 * @phpstan-import-type ComposerJsonArray from ComposerPackage
 */
class DumpAutoload
{
    use LoggerAwareTrait;

    protected AutoloadConfigInterface $config;

    protected FileSystem $filesystem;

    protected Prefixer $projectReplace;

    protected FileEnumerator $fileEnumerator;

    public function __construct(
        AutoloadConfigInterface $config,
        Filesystem $filesystem,
        LoggerInterface $logger,
        Prefixer $projectReplace,
        FileEnumerator $fileEnumerator
    ) {
        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->setLogger($logger);
        $this->projectReplace = $projectReplace;
        $this->fileEnumerator = $fileEnumerator;
    }

    /**
     * Create `autoload.php` and the `vendor-prefixed/composer` directory.
     * @throws ParsingException
     * @throws FilesystemException
     */
    public function generatedPrefixedAutoloader(): void
    {
        $this->generatedMainAutoloader();

        $this->createInstalledVersionsFiles();

        $this->prefixNewAutoloader();
    }

    /**
     * Uses `vendor/composer/installed.json` to output autoload files to `vendor-prefixed/composer`.
     *
     * @throws ParsingException
     * @throws FilesystemException
     */
    protected function generatedMainAutoloader(): void
    {
        /**
         * Unfortunately, `::dump()` creates the target directories if they don't exist, even though it otherwise respects `::setDryRun()`.
         *
         * {@see https://github.com/composer/composer/pull/12396} might fix this.
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

        /** @var ComposerJsonArray $projectComposerJson */
        $projectComposerJson = new JsonFile($this->config->getProjectDirectory() . Factory::getComposerFile());

        $projectComposerJsonArray = $projectComposerJson->read();
        if (isset($projectComposerJsonArray['config'], $projectComposerJsonArray['config']['vendor-dir'])) {
            $projectComposerJsonArray['config']['vendor-dir'] = $relativeTargetDir;
        }

        /**
         * Loop over all packages that should be included and ensure the root package requires them. Composer only
         * includes packages in the autoloader that are required by a parent package (including root). Without this
         * packages that are selectively prefixed are not included in the autoloader.
         *
         * @see AutoloadGenerator::filterPackageMap()
         */
        foreach ($this->config->getPackagesToPrefix() as $name => $package) {
            $projectComposerJsonArray['require'][$name] = '*';
        }

        // Include the project root autoload in the vendor-prefixed autoloader?
        if (isset($projectComposerJsonArray['autoload']) && !$this->config->isIncludeRootAutoload()) {
            $projectComposerJsonArray['autoload'] = [];
        }

        $composer = Factory::create(new NullIO(), $projectComposerJsonArray);
        $installationManager = $composer->getInstallationManager();
        $package = $composer->getPackage();

        /**
         * Cannot use `$composer->getConfig()`, need to create a new one so the `vendor-dir` is correct.
         */
        $config = new Config(false, $this->config->getProjectDirectory());

        /** @var array{config?: array<string, mixed>} $projectComposerConfigMergeArray */
        $projectComposerConfigMergeArray = ['config' => $projectComposerJsonArray['config'] ?? []];

        $config->merge($projectComposerConfigMergeArray);

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

        $installedJsonFile = new JsonFile($this->config->getTargetDirectory() . 'composer/installed.json');
        /** @var array{dev?:bool} $installedJson */
        $installedJson = $installedJsonFile->read();
        $localRepo = new InstalledFilesystemRepository($installedJsonFile);

        /**
         * If the target directory is different to the vendor directory, then we do not want to include dev
         * dependencies, but if it is vendor, then unless composer install was run with --no-dev, we do want them.
         */
        if ($this->config->getVendorDirectory() !== $this->config->getTargetDirectory()) {
            $isDevMode = false;
        } else {
            $isDevMode = (bool) ($installedJson['dev'] ?? false);
        }
        $generator->setDevMode($isDevMode);

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
    }

    /**
     * Create `InstalledVersions.php` and `installed.php`.
     *
     * This file is copied in all Composer installations.
     * It is added always in `ComposerAutoloadGenerator::dump()`, called above.
     * If the file does not exist, its entry in the classmap will not be prefixed and will cause autoloading issues for the real class.
     *
     * The accompanying `installed.php` is unique per install. Copy it and filter its packages to the packages that was copied.
     * @throws FilesystemException
     */
    protected function createInstalledVersionsFiles(): void
    {
        if ($this->config->getVendorDirectory() === $this->config->getTargetDirectory()) {
            return;
        }

        $this->filesystem->copy($this->config->getVendorDirectory() . '/composer/InstalledVersions.php', $this->config->getTargetDirectory() . 'composer/InstalledVersions.php');

        // This is just `<?php return array(...);`
        $installedPhpString = $this->filesystem->read($this->config->getVendorDirectory() . '/composer/installed.php');
        $installed = eval(str_replace('<?php', '', $installedPhpString));

        $targetPackages = $this->config->getPackagesToCopy();
        $targetPackagesNames = array_keys($targetPackages);

        $installed['versions'] = array_filter($installed['versions'], function ($packageName) use ($targetPackagesNames) {
            return in_array($packageName, $targetPackagesNames);
        }, ARRAY_FILTER_USE_KEY);

        $installedArrayString = var_export($installed, true);

        $newInstalledPhpString = "<?php return $installedArrayString;";

        // Update `__DIR__` which was evaluated during the `include`/`eval`.
        $newInstalledPhpString = preg_replace('/(\'install_path\' => )(.*)(\/\.\..*)/', "$1__DIR__ . '$3", $newInstalledPhpString);

        $this->filesystem->write($this->config->getTargetDirectory() . '/composer/installed.php', $newInstalledPhpString);
    }

    /**
     * @throws FilesystemException
     */
    protected function prefixNewAutoloader(): void
    {
        if ($this->config->getVendorDirectory() === $this->config->getTargetDirectory()) {
            return;
        }

        $this->logger->debug('Prefixing the new Composer autoloader.');

        $projectFiles = $this->fileEnumerator->compileFileListForPaths([
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

        $sourceFile = new File(__DIR__, __DIR__);
        $composerAutoloadNamespaceSymbol = new NamespaceSymbol(
            'Composer\\Autoload',
            $sourceFile
        );
        $composerAutoloadNamespaceSymbol->setReplacement(
            $this->config->getNamespacePrefix() . '\\Composer\\Autoload'
        );
        $composerNamespaceSymbol = new NamespaceSymbol(
            'Composer',
            $sourceFile
        );
        $composerNamespaceSymbol->setReplacement(
            $this->config->getNamespacePrefix() . '\\Composer'
        );

        $discoveredSymbols = new DiscoveredSymbols();
        $discoveredSymbols->add(
            $composerNamespaceSymbol
        );
        $discoveredSymbols->add(
            $composerAutoloadNamespaceSymbol
        );

        $this->projectReplace->replaceInProjectFiles($discoveredSymbols, $phpFilesAbsolutePaths);
    }

    /**
     * If there is an existing autoloader, it will use the same suffix. If there is not, it pulls the suffix from
     * {Composer::getLocker()} and clashes with the existing autoloader.
     *
     * @see https://github.com/composer/composer/blob/ae208dc1e182bd45d99fcecb956501da212454a1/src/Composer/Autoload/AutoloadGenerator.php#L429
     * @see AutoloadGenerator::dump() 412:431
     * @throws \Random\RandomException in PHP 8.2+
     * @throws FilesystemException
     */
    protected function getSuffix(): ?string
    {
        return !$this->filesystem->fileExists($this->config->getTargetDirectory() . 'autoload.php')
            ? bin2hex(random_bytes(16))
            : null;
    }
}
