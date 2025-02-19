<?php

namespace BrianHenryIE\Strauss\Pipeline\Autoload;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
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

    protected string $workingDir;

    protected FileSystem $filesystem;

    /**
     * Autoload constructor.
     *
     * @param StraussConfig $config
     * @param string $workingDir
     * @param array<string, array<string>> $discoveredFilesAutoloaders
     */
    public function __construct(
        string $workingDir,
        AutoloadConfigInterace $config,
        Filesystem $filesystem,
        ?LoggerInterface $logger = null
    ) {
        $this->workingDir = $workingDir;
        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->setLogger($logger ?? new NullLogger());
    }

    protected function getTargetDirectory(): string
    {
        return $this->workingDir . $this->config->getTargetDirectory();
    }

    /**
     * Uses `vendor/composer/installed.json` to output autoload files to `vendor-prefixed/composer`.
     */
    public function generatedPrefixedAutoloader(string $workingDir, string $relativeTargetDir)
    {
        $defaultVendorDirBefore = Config::$defaultConfig['vendor-dir'];

        Config::$defaultConfig['vendor-dir'] = $relativeTargetDir;

        $composer = Factory::create(new NullIO(), $workingDir . 'composer.json');
        $installationManager = $composer->getInstallationManager();
        $package = $composer->getPackage();

        /**
         * Cannot use `$composer->getConfig()`, need to create a new one so the vendor-dir is correct.
         */
        $config = new \Composer\Config(false, $workingDir);

        $generator = $composer->getAutoloadGenerator();
        $generator->setDryRun($this->config->isDryRun());

        //        $generator->setClassMapAuthoritative($authoritative);
        $generator->setClassMapAuthoritative(true);

        $generator->setRunScripts(false);
//        $generator->setApcu($apcu, $apcuPrefix);
//        $generator->setPlatformRequirementFilter($this->getPlatformRequirementFilter($input));
        $optimize = false; // $input->getOption('optimize') || $config->get('optimize-autoloader');
        $generator->setDevMode(false);

        // If delete vendor files is false, we shouldn't be editing this. Maybe we should create a second one.
        $localRepo = new InstalledFilesystemRepository(new JsonFile($workingDir . $relativeTargetDir . 'composer/installed.json'));

        // This will output the autoload_static.php etc files to vendor-prefixed/composer
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

        // Tests fail if this is absent.
        Config::$defaultConfig['vendor-dir'] = $defaultVendorDirBefore;
    }

    /**
     * If there is an existing autoloader, it will use the same suffix. If there is not, it pulls the suffix from
     * {Composer::getLocker()} and clashes with the existing autoloader.
     *
     * @see AutoloadGenerator::dump() 412:431
     */
    protected function getSuffix(): ?string
    {
        return !$this->filesystem->fileExists($this->getTargetDirectory() . 'autoload.php')
            ? bin2hex(random_bytes(16))
            : null;
    }
}
