<?php
/**
 * Generate an `autoload.php` file in the root of the target directory.
 *
 * @see \Composer\Autoload\ClassMapGenerator
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Config\AutoloadConfigInterace;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Pipeline\Autoload\DumpAutoload;
use BrianHenryIE\Strauss\Pipeline\Autoload\VendorComposerAutoload;
use BrianHenryIE\Strauss\Pipeline\Cleanup\InstalledJson;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use League\Flysystem\WhitespacePathNormalizer;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Autoload
{
    use LoggerAwareTrait;

    protected FileSystem $filesystem;

    protected string $workingDir;

    protected AutoloadConfigInterace $config;

    /**
     * The files autoloaders of packages that have been copied by Strauss.
     * Keyed by package path.
     *
     * @var array<string, array<string>> $discoveredFilesAutoloaders Array of packagePath => array of relativeFilePaths.
     */
    protected array $discoveredFilesAutoloaders;

    protected string $absoluteTargetDirectory;

    /**
     * Autoload constructor.
     *
     * @param StraussConfig $config
     * @param string $workingDir
     * @param array<string, array<string>> $discoveredFilesAutoloaders
     */
    public function __construct(
        AutoloadConfigInterace $config,
        string $workingDir,
        array $discoveredFilesAutoloaders,
        Filesystem $filesystem,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->workingDir = $workingDir;
        $this->discoveredFilesAutoloaders = $discoveredFilesAutoloaders;

        $this->filesystem = $filesystem;
        $this->setLogger($logger ?? new NullLogger());


        $targetDirectory = ltrim(sprintf(
            '%s/%s/',
            trim($this->workingDir, '/\\'),
            trim($this->config->getTargetDirectory(), '/\\')
        ), '/\\');

        $pathNormalizer = new WhitespacePathNormalizer();
        $targetDirectory = $pathNormalizer->normalizePath($targetDirectory) . '/';

        $targetDir = $this->config->isDryRun()
            ? 'mem://' . ltrim($targetDirectory, '/')
            : $targetDirectory;

        $this->absoluteTargetDirectory = $targetDir;

        $this->logger->debug('Using target directory: ' . $this->absoluteTargetDirectory);
    }

    public function generate(array $flatDependencyTree, DiscoveredSymbols $discoveredSymbols): void
    {
        // Use native Composer's `autoload.php` etc. when the target directory is the vendor directory.
        if ($this->config->getTargetDirectory() === $this->config->getVendorDirectory()) {
            $this->logger->debug('Not generating autoload.php because the target directory is the vendor directory.');
            return;
        }

        if (!$this->config->isClassmapOutput()) {
            $this->logger->debug('Not generating autoload.php because classmap output is disabled.');
            return;
        }

        $this->logger->info('Generating autoload files for ' . $this->config->getTargetDirectory());

        $installedJson = new InstalledJson(
            $this->workingDir,
            $this->config,
            $this->filesystem,
            $this->logger
        );
        $installedJson->createAndCleanTargetDirInstalledJson($flatDependencyTree, $discoveredSymbols);

        (new DumpAutoload(
            $this->workingDir,
            $this->config,
            $this->filesystem,
            $this->logger
        ))->generatedPrefixedAutoloader($this->workingDir, $this->config->getTargetDirectory());
    }
}
