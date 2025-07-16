<?php
/**
 * Build a list of ComposerPackage objects for all dependencies.
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use Composer\Factory;
use Exception;
use League\Flysystem\FilesystemReader;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class DependenciesEnumerator
{
    use LoggerAwareTrait;

    /**
     * @var string[]
     */
    protected array $requiredPackageNames;

    protected FileSystem $filesystem;

    /** @var string[]  */
    protected array $virtualPackages = array(
        'php-http/client-implementation'
    );

    /** @var array<string, ComposerPackage> */
    protected array $flatDependencyTree = array();

    /**
     * Record the files autoloaders for later use in building our own autoloader.
     *
     * Package-name: [ dir1, file1, file2, ... ].
     *
     * @var array<string, string[]>
     */
    protected array $filesAutoloaders = [];

    /**
     * @var array{}|array<string, array{files?:array<string>,classmap?:array<string>,"psr-4":array<string|array<string>>}> $overrideAutoload
     */
    protected array $overrideAutoload = array();
    protected StraussConfig $config;

    /**
     * Constructor.
     *
     * @param StraussConfig $config
     */
    public function __construct(
        StraussConfig $config,
        FileSystem $filesystem,
        ?LoggerInterface $logger = null
    ) {
        $this->overrideAutoload = $config->getOverrideAutoload();
        $this->requiredPackageNames = $config->getPackages();

        $this->filesystem = $filesystem;
        $this->config = $config;

        $this->setLogger($logger ?? new NullLogger());
    }

    /**
     * @return array<string, ComposerPackage> Packages indexed by package name.
     * @throws Exception
     */
    public function getAllDependencies(): array
    {
        $this->recursiveGetAllDependencies($this->requiredPackageNames);

        return $this->flatDependencyTree;
    }

    /**
     * @param string[] $requiredPackageNames
     */
    protected function recursiveGetAllDependencies(array $requiredPackageNames): void
    {
        $requiredPackageNames = array_filter($requiredPackageNames, array( $this, 'removeVirtualPackagesFilter' ));

        $installedJsonPath = sprintf("%scomposer/installed.json", $this->config->getVendorDirectory());
        $installedJson = json_decode($this->filesystem->read($installedJsonPath), true);
        $installedJsonPackages = [];
        foreach ($installedJson['packages'] as $package) {
            $installedJsonPackages[$package['name']] = $package;
        }

        foreach ($requiredPackageNames as $requiredPackageName) {
            // Avoid infinite recursion.
            if (isset($this->flatDependencyTree[$requiredPackageName])) {
                continue;
            }

            $packageComposerFile = sprintf(
                '%s%s/composer.json',
                $this->config->getVendorDirectory(),
                $requiredPackageName
            );
            $packageComposerFile = $this->filesystem->normalize($packageComposerFile);

            $overrideAutoload = $this->overrideAutoload[ $requiredPackageName ] ?? null;

            if ($this->filesystem->fileExists($packageComposerFile)) {
                $requiredComposerPackage = ComposerPackage::fromFile(
                    $this->filesystem->prefixPath($packageComposerFile),
                    $overrideAutoload
                );
            } else {
                // Some packages download with NO `composer.json`! E.g. woocommerce/action-scheduler.
                // Some packages download to a different directory than the package name.
                $this->logger->debug('Could not find ' . $requiredPackageName . '\'s composer.json in vendor dir, trying composer.lock');

                // TODO: These (.json, .lock) should be read once and reused.
                $composerJsonString = $this->filesystem->read($this->config->getProjectDirectory() . Factory::getComposerFile());
                $composerJson       = json_decode($composerJsonString, true);

                if (isset($composerJson['provide']) && in_array($requiredPackageName, array_keys($composerJson['provide']))) {
                    $this->logger->info('Skipping ' . $requiredPackageName . ' as it is in the composer.json provide list');
                    continue;
                }

                $composerLockPath = $this->config->getProjectDirectory() . Factory::getLockFile(Factory::getComposerFile());
                $composerLockString     = $this->filesystem->read($composerLockPath);
                $composerLock           = json_decode($composerLockString, true);

                $requiredPackageComposerJson = null;
                foreach ($composerLock['packages'] as $packageJson) {
                    if ($requiredPackageName === $packageJson['name']) {
                        $requiredPackageComposerJson = $packageJson;
                        break;
                    }
                }

                if (is_null($requiredPackageComposerJson)) {
                    // e.g. composer-plugin-api, composer-runtime-api
                    $this->logger->info('Skipping ' . $requiredPackageName . ' as it is not in composer.lock');
                    continue;
                }

                if (!isset($requiredPackageComposerJson['autoload'])
                    && empty($requiredPackageComposerJson['require'])
                    && $requiredPackageComposerJson['type'] != 'metapackage'
                    && ! $this->filesystem->directoryExists(dirname($packageComposerFile))
                ) {
                    // e.g. symfony/polyfill-php72 when installed on PHP 7.2 or later.
                    $this->logger->info('Skipping ' . $requiredPackageName . ' as it is has no autoload key (possibly a polyfill unnecessary for this version of PHP).');
                    continue;
                }

                $requiredComposerPackage = ComposerPackage::fromComposerJsonArray($requiredPackageComposerJson, $overrideAutoload);
            }

            $installedPackage = $installedJsonPackages[$requiredPackageName];
            if (isset($installedPackage['dist'], $installedPackage['dist']['type']) && $installedPackage['dist']['type'] === 'path') {
                $path = $installedPackage['dist']['url'];

                $packageRealPath = $this->filesystem->normalize($this->config->getProjectDirectory() . $path);
                $requiredComposerPackage->setRealpath(
                    $packageRealPath
                );
            }
            unset($installedPackage);
            $requiredComposerPackage->setProjectVendorDirectory(
                $this->filesystem->normalize(
                    $this->config->getVendorDirectory()
                )
            );

            $this->logger->info('Analysing package ' . $requiredComposerPackage->getPackageName());
            $this->flatDependencyTree[$requiredComposerPackage->getPackageName()] = $requiredComposerPackage;

            $nextRequiredPackageNames = $requiredComposerPackage->getRequiresNames();

            if (0 !== count($nextRequiredPackageNames)) {
                $packageRequiresString = $requiredComposerPackage->getPackageName() . ' requires packages: ';
                $this->logger->debug($packageRequiresString . implode(', ', $nextRequiredPackageNames));
            } else {
                $this->logger->debug($requiredComposerPackage->getPackageName() . ' requires no packages.');
                continue;
            }

            $newPackages = array_diff($nextRequiredPackageNames, array_keys($this->flatDependencyTree));

            $newPackagesString = implode(', ', $newPackages);
            if (!empty($newPackagesString)) {
                $this->logger->debug(sprintf(
                    'New packages: %s%s',
                    str_repeat(' ', strlen($packageRequiresString) - strlen('New packages: ')),
                    $newPackagesString
                ));
            } else {
                $this->logger->debug('No new packages.');
                continue;
            }

            $this->recursiveGetAllDependencies($newPackages);
        }
    }

    /**
     * Get the recorded files autoloaders.
     *
     * @return array<string, array<string>>
     */
    public function getAllFilesAutoloaders(): array
    {
        $filesAutoloaders = array();
        foreach ($this->flatDependencyTree as $packageName => $composerPackage) {
            if (isset($composerPackage->getAutoload()['files'])) {
                $filesAutoloaders[$packageName] = $composerPackage->getAutoload()['files'];
            }
        }
        return $filesAutoloaders;
    }

    /**
     * Unset PHP, ext-*, ...
     *
     * @param string $requiredPackageName
     */
    protected function removeVirtualPackagesFilter(string $requiredPackageName): bool
    {
        return ! (
            0 === strpos($requiredPackageName, 'ext')
            // E.g. `php`, `php-64bit`.
            || (0 === strpos($requiredPackageName, 'php') && false === strpos($requiredPackageName, '/'))
            || in_array($requiredPackageName, $this->virtualPackages)
        );
    }
}
