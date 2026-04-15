<?php
/**
 * Generate an `autoload.php` file in the root of the target directory.
 *
 * @see \Composer\Autoload\ClassMapGenerator
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Config\AutoloadConfigInterface;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Pipeline\Autoload\DumpAutoload;
use BrianHenryIE\Strauss\Pipeline\Cleanup\InstalledJson;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use Exception;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Seld\JsonLint\ParsingException;

class Autoload
{
    use LoggerAwareTrait;

    protected FileSystem $filesystem;

    /**
     * @var StraussConfig&AutoloadConfigInterface
     */
    protected StraussConfig $config;

    /**
     * The files autoloaders of packages that have been copied by Strauss.
     * Keyed by package path.
     *
     * @var array<string, array<string>> $discoveredFilesAutoloaders Array of packagePath => array of relativeFilePaths.
     */
    protected array $discoveredFilesAutoloaders;

    /**
     * Autoload constructor.
     *
     * @param StraussConfig&AutoloadConfigInterface $config
     * @param array<string, array<string>> $discoveredFilesAutoloaders
     */
    public function __construct(
        StraussConfig $config,
        array $discoveredFilesAutoloaders,
        Filesystem $filesystem,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->discoveredFilesAutoloaders = $discoveredFilesAutoloaders;
        $this->filesystem = $filesystem;
        $this->setLogger($logger ?? new NullLogger());
    }

    /**
     * @param array<string,ComposerPackage> $flatDependencyTree
     * @throws FilesystemException
     * @throws ParsingException
     * @throws Exception
     */
    public function generate(array $flatDependencyTree, DiscoveredSymbols $discoveredSymbols): void
    {
        if (!$this->config->isClassmapOutput()) {
            $this->logger->debug('Not generating autoload.php because classmap output is disabled.');
            // TODO: warn about `files` autoloaders.
            // TODO: list the files autoloaders that will be missed.
            return;
        }

        $this->logger->info('Generating autoload files for ' . $this->config->getTargetDirectory());

        if ($this->config->getVendorDirectory() !== $this->config->getTargetDirectory()) {
            $installedJson = new InstalledJson(
                $this->config,
                $this->filesystem,
                $this->logger
            );
            $installedJson->cleanTargetDirInstalledJson($flatDependencyTree, $discoveredSymbols);
        }

        (new DumpAutoload(
            $this->config,
            $this->filesystem,
            $this->logger,
            new Prefixer(
                $this->config,
                $this->filesystem,
                $this->logger
            ),
            new FileEnumerator(
                $this->config,
                $this->filesystem,
                $this->logger
            )
        ))->generatedPrefixedAutoloader();
    }
}
