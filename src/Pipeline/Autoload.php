<?php
/**
 * Generate an `autoload.php` file in the root of the target directory.
 *
 * @package brianhenryie/strauss
 *
 * @see \Composer\Autoload\ClassMapGenerator
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\DependenciesCollection;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Config\AutoloadConfigInterface;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\Pipeline\Autoload\ComposerAutoloadGeneratorFactory;
use BrianHenryIE\Strauss\Pipeline\Autoload\DumpAutoload;
use BrianHenryIE\Strauss\Pipeline\Cleanup\Cleanup;
use BrianHenryIE\Strauss\Pipeline\Cleanup\InstalledJson;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use Exception;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Seld\JsonLint\ParsingException;

/**
 * Does nothing unless classmap output is enabled. When the target directory is not `vendor`, first uses
 * {@see InstalledJson} to rewrite the target directory's `composer/installed.json`, then runs {@see DumpAutoload}
 * (built with a {@see Prefixer}, {@see FileEnumerator} and {@see ComposerAutoloadGeneratorFactory}) to write the
 * autoloaders, and finally uses {@see Cleanup::stripPharPrefix()} on the generated `composer` directory.
 */
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
     * @param StraussConfig&AutoloadConfigInterface $config                     Strauss config, used for target/vendor directories and classmap output settings.
     * @param array<string, array<string>>          $discoveredFilesAutoloaders Package path => relative file paths of `files` autoloaders (currently unused; callers pass []).
     */
    public function __construct(
        StraussConfig $config,
        array $discoveredFilesAutoloaders,
        FileSystem $filesystem,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->discoveredFilesAutoloaders = $discoveredFilesAutoloaders;
        $this->filesystem = $filesystem;
        $this->setLogger($logger ?? new NullLogger());
    }

    /**
     * @param  DependenciesCollection $flatDependencyTree All dependencies (flattened), used to update package paths in the target directory's installed.json.
     * @throws FilesystemException
     * @throws ParsingException
     * @throws Exception
     */
    public function generate(DependenciesCollection $flatDependencyTree, DiscoveredSymbols $discoveredSymbols): void
    {
        if (!$this->config->isClassmapOutput()) {
            $this->logger->debug('Not generating autoload.php because classmap output is disabled.');
            // TODO: warn about `files` autoloaders.
            // TODO: list the files autoloaders that will be missed.
            return;
        }

        $this->logger->info('Generating autoload files for ' . $this->config->getAbsoluteTargetDirectory());

        if (!$this->config->isTargetDirectoryVendor()) {
            $installedJson = new InstalledJson(
                $this->config,
                $this->filesystem,
                $this->logger
            );
            $installedJson->cleanTargetDirInstalledJson($flatDependencyTree, $discoveredSymbols);
        }

        $this->dumpAutoload();
    }

    /**
     * Regenerate the autoload files from the target directory's existing `composer/installed.json`, i.e. after
     * the packages have already been copied and prefixed.
     *
     * @throws FilesystemException
     * @throws ParsingException
     */
    public function dumpAutoload(): void
    {
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
            ),
            new ComposerAutoloadGeneratorFactory()
        ))->generatedPrefixedAutoloader();

        (new Cleanup(
            $this->config,
            $this->filesystem,
            $this->logger
        ))->stripPharPrefix($this->config->getAbsoluteTargetDirectory() . '/composer');
    }
}
