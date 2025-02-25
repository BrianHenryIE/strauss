<?php

namespace BrianHenryIE\Strauss\Pipeline\Autoload;

use BrianHenryIE\Strauss\Config\AutoloadConfigInterace;
use BrianHenryIE\Strauss\Helpers\FileSystem;
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

    protected AutoloadConfigInterace $config;

    protected FileSystem $filesystem;

    /**
     * Autoload constructor.
     *
     * @param AutoloadConfigInterace $config
     * @param array<string, array<string>> $discoveredFilesAutoloaders
     */
    public function __construct(
        AutoloadConfigInterace $config,
        Filesystem $filesystem,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->setLogger($logger ?? new NullLogger());
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

        $composer = Factory::create(new NullIO(), $this->config->getProjectDirectory() . 'composer.json');
        $installationManager = $composer->getInstallationManager();
        $package = $composer->getPackage();

        /**
         * Cannot use `$composer->getConfig()`, need to create a new one so the vendor-dir is correct.
         */
        $config = new \Composer\Config(false, $this->config->getProjectDirectory());

        $generator = $composer->getAutoloadGenerator();
        $generator->setDryRun($this->config->isDryRun());

        //        $generator->setClassMapAuthoritative($authoritative);
        $generator->setClassMapAuthoritative(true);

        $generator->setRunScripts(false);
//        $generator->setApcu($apcu, $apcuPrefix);
//        $generator->setPlatformRequirementFilter($this->getPlatformRequirementFilter($input));
        $optimize = false; // $input->getOption('optimize') || $config->get('optimize-autoloader');
        $generator->setDevMode(false);

        $localRepo = new InstalledFilesystemRepository(new JsonFile($this->config->getTargetDirectory() . 'composer/installed.json'));

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
            false, // $input->getOption('strict-ambiguous')
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
