<?php

/**
 * Build a list of ComposerPackage objects for all dependencies.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\DeepDependenciesCollection;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\Pipeline\Cleanup\InstalledJson;
use Composer\Factory;
use Composer\Util\Platform;
use Exception;
use JsonException;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @phpstan-import-type InstalledJsonArray from InstalledJson
 * @phpstan-import-type InstalledJsonPackageArray from InstalledJson
 * @phpstan-import-type ComposerJsonArray from ComposerPackage
 * @phpstan-import-type AutoloadKeyArray from ComposerPackage
 */
class DependenciesEnumerator
{
    use LoggerAwareTrait;

    /**
     * @var string[]
     */
    protected array $requiredPackageNames;

    protected FileSystem $filesystem;

    /**
     * @var string[]
     */
    protected array $virtualPackages = [
        'php-http/client-implementation',
    ];

    /**
     * @var array<string,ComposerPackage>
     */
    protected array $flatDependencyArray = [];

    /**
     * Record the files autoloaders for later use in building our own autoloader.
     *
     * Package-name: [ dir1, file1, file2, ... ].
     *
     * @var array<string, string[]>
     */
    protected array $filesAutoloaders = [];

    /**
     * @var array{}|array<string, AutoloadKeyArray> $overrideAutoload
     */
    protected array $overrideAutoload = [];
    protected StraussConfig $config;

    /**
     * Constructor.
     *
     * @param StraussConfig    $config     The required packages, autoload overrides, and project and vendor directories.
     * @param FileSystem       $filesystem Used to read `composer.json`, `composer.lock` and packages' `composer.json` files.
     * @param ?LoggerInterface $logger     Optional logger; a NullLogger is used when null.
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
     * @throws Exception
     * @throws FilesystemException
     */
    public function getAllDependencies(): DeepDependenciesCollection
    {
        $this->recursiveGetAllDependencies($this->requiredPackageNames);

        /**
 * @var array<string, ComposerPackage[]> $providers The packages which `provide` or `replace` each name.
*/
        $providers = [];
        foreach ($this->flatDependencyArray as $composerPackage) {
            foreach ($composerPackage->getProvidesNames() as $providesName) {
                $providers[$providesName][] = $composerPackage;
            }
        }

        foreach ($this->flatDependencyArray as $composerPackage) {
            foreach ($composerPackage->getRequiresNames() as $requiresName) {
                if (isset($this->flatDependencyArray[$requiresName])) {
                    $composerPackage->addDependency($this->flatDependencyArray[$requiresName]);
                    continue;
                }
                // A virtual (e.g. `psr/log-implementation`) or replaced package: the requirement is satisfied by
                // whichever installed packages `provide` or `replace` it.
                foreach ($providers[$requiresName] ?? [] as $provider) {
                    if ($provider !== $composerPackage) {
                        $composerPackage->addDependency($provider);
                    }
                }
            }
            foreach ($composerPackage->getSuggestsNames() as $suggestsName) {
                if (isset($this->flatDependencyArray[$suggestsName])) {
                    $composerPackage->addSuggestedPackage($this->flatDependencyArray[$suggestsName]);
                }
            }
        }

        return new DeepDependenciesCollection($this->flatDependencyArray);
    }

    /**
     * @param string[] $requiredPackageNames Composer package names, e.g. `psr/log`; virtual packages (php, ext-*) are filtered out.
     *
     * @throws FilesystemException
     * @throws JsonException
     * @throws Exception
     */
    protected function recursiveGetAllDependencies(array $requiredPackageNames): void
    {
        $requiredPackageNames = array_filter($requiredPackageNames, [ $this, 'removeVirtualPackagesFilter' ]);

        $installedJsonPackages = $this->getInstalledJsonPackages();

        foreach ($requiredPackageNames as $requiredPackageName) {
            // Avoid infinite recursion.
            if (isset($this->flatDependencyArray[$requiredPackageName])) {
                continue;
            }

            $requiredComposerPackage = $this->loadPackage($requiredPackageName, $installedJsonPackages);
            if (is_null($requiredComposerPackage)) {
                continue;
            }


            $this->logger->info('Analysing package ' . $requiredComposerPackage->getPackageName());
            $this->flatDependencyArray[$requiredComposerPackage->getPackageName()] = $requiredComposerPackage;

            $nextRequiredPackageNames = $requiredComposerPackage->getRequiresNames();

            if (0 !== count($nextRequiredPackageNames)) {
                $packageRequiresString = $requiredComposerPackage->getPackageName() . ' requires packages: ';
                $this->logger->debug($packageRequiresString . implode(', ', $nextRequiredPackageNames));
            } else {
                $this->logger->debug($requiredComposerPackage->getPackageName() . ' requires no packages.');
                continue;
            }

            $newPackages = array_diff($nextRequiredPackageNames, array_keys($this->flatDependencyArray));

            $newPackagesString = implode(', ', $newPackages);
            if (!empty($newPackagesString)) {
                $this->logger->debug(
                    sprintf(
                        'New packages: %s%s',
                        str_repeat(' ', strlen($packageRequiresString) - strlen('New packages: ')),
                        $newPackagesString
                    )
                );
            } else {
                $this->logger->debug('No new packages.');
                continue;
            }

            $this->recursiveGetAllDependencies($newPackages);
        }
    }

    /**
     * The non-dev packages installed in the vendor directory which are not being prefixed, e.g. packages which use the
     * prefixed packages but are not themselves listed in `packages`, and those in `exclude_from_prefix.packages`.
     *
     * When the target directory is the vendor directory, these stay where they are, so their uses of the prefixed
     * symbols must be updated, while their own symbols are not renamed.
     *
     * Call after {@see self::getAllDependencies()} so the packages already loaded are reused.
     *
     * @param array<string, ComposerPackage> $packagesToPrefix Keyed by package name.
     *
     * @return array<string, ComposerPackage> Keyed by package name.
     *
     * @throws FilesystemException
     * @throws JsonException
     */
    public function getUnprefixedPackages(array $packagesToPrefix): array
    {
        $installedJsonPackages = $this->getInstalledJsonPackages();

        $unprefixedPackages = [];
        foreach ($this->getNonDevInstalledPackageNames() as $packageName) {
            if (
                isset($packagesToPrefix[$packageName])
                || 'brianhenryie/strauss' === $packageName
                || !$this->removeVirtualPackagesFilter($packageName)
            ) {
                continue;
            }

            $package = $this->flatDependencyArray[$packageName]
                ?? $this->loadPackage($packageName, $installedJsonPackages);

            if (is_null($package) || is_null($package->getPackageAbsolutePath())) {
                continue;
            }

            $unprefixedPackages[$packageName] = $package;
        }

        $this->logger->debug(
            'Unprefixed packages: {packageNames}',
            [
                'packageNames' => implode(', ', array_keys($unprefixedPackages)),
            ]
        );

        return $unprefixedPackages;
    }

    /**
     * The packages in `vendor/composer/installed.json`, except those in its `dev-package-names`.
     *
     * @return string[]
     * @throws FilesystemException
     */
    protected function getNonDevInstalledPackageNames(): array
    {
        $installedJson = $this->readInstalledJson();

        return array_values(
            array_diff(
                array_map(
                    fn(array $package): string => $package['name'],
                    $installedJson['packages']
                ),
                $installedJson['dev-package-names']
            )
        );
    }

    /**
     * @return InstalledJsonArray
     * @throws FilesystemException
     */
    protected function readInstalledJson(): array
    {
        $installedJsonPath = $this->filesystem->makeAbsolute(
            sprintf(
                "%s/composer/installed.json",
                $this->config->getAbsoluteVendorDirectory()
            )
        );
        $installedJsonTxt = $this->filesystem->read($installedJsonPath);
        /**
         * @var InstalledJsonArray $installedJson
         */
        $installedJson = json_decode($installedJsonTxt, true);

        return $installedJson;
    }

    /**
     * @return array<string, InstalledJsonPackageArray> The `installed.json` package entries, keyed by package name.
     * @throws FilesystemException
     */
    protected function getInstalledJsonPackages(): array
    {
        $installedJsonPackages = [];
        foreach ($this->readInstalledJson()['packages'] as $package) {
            $installedJsonPackages[$package['name']] = $package;
        }
        return $installedJsonPackages;
    }

    /**
     * Load one installed package from its `composer.json`, falling back to `composer.lock`.
     *
     * @param string                                   $requiredPackageName   The package's name, e.g. `psr/log`.
     * @param array<string, InstalledJsonPackageArray> $installedJsonPackages The `installed.json` package entries, keyed by package name.
     *
     * @return ?ComposerPackage Null when the package is provided by the project, is not in `composer.lock`, or is
     *                          an unnecessary polyfill.
     *
     * @throws FilesystemException
     * @throws JsonException
     * @throws Exception
     */
    protected function loadPackage(string $requiredPackageName, array $installedJsonPackages): ?ComposerPackage
    {
        $packageComposerFile = sprintf(
            '%s/%s/composer.json',
            $this->config->getAbsoluteVendorDirectory(),
            $requiredPackageName
        );

        /**
         * 1. Remove `mem://`
         * 2. Add `c:\` or `/`
         *
         * @see https://github.com/composer/composer/pull/12396
         */
        $packageComposerFile = $this->filesystem->normalizePath($packageComposerFile);
        $packageComposerFile = $this->filesystem->makeAbsolute($packageComposerFile);

        $overrideAutoload = $this->overrideAutoload[ $requiredPackageName ] ?? null;

        if ($this->filesystem->fileExists($packageComposerFile)) {
            $this->logger->debug(
                'Loading ComposerPackage::fromFile {packageComposerFilePath}',
                [
                    'packageComposerFilePath' => $packageComposerFile,
                ]
            );

            /**
             * TODO: Calling `::makeAbsolute()` should be unnecessary when Composer PR merged.
             *
             * @see https://github.com/composer/composer/pull/12396
             */
            $requiredComposerPackage = ComposerPackage::fromFile(
                $this->filesystem->makeAbsolute($packageComposerFile),
                $overrideAutoload
            );
            $requiredComposerPackage->setRealpath(
                $this->filesystem->normalizePath(
                    Platform::realpath(
                        $this->filesystem->makeAbsolute($packageComposerFile)
                    )
                )
            );
        } else {
            // Some packages download with NO `composer.json`! E.g. woocommerce/action-scheduler.
            // Some packages download to a different directory than the package name.
            $this->logger->debug('Could not find ' . $requiredPackageName . '\'s composer.json in vendor dir, trying composer.lock: ' . $packageComposerFile);

            // TODO: These (.json, .lock) should be read once and reused.
            $composerJsonString = $this->filesystem->read($this->config->getProjectAbsolutePath() . '/' . Factory::getComposerFile());
            /**
 * @var ComposerJsonArray $composerJson
*/
            $composerJson       = json_decode($composerJsonString, true, 512, JSON_THROW_ON_ERROR);

            if (isset($composerJson['provide']) && in_array($requiredPackageName, array_keys($composerJson['provide']))) {
                $this->logger->info('Skipping ' . $requiredPackageName . ' as it is in the composer.json provide list');
                return null;
            }

            $composerLockPath = $this->config->getProjectAbsolutePath() . '/' . Factory::getLockFile(Factory::getComposerFile());
            $composerLockString     = $this->filesystem->read($composerLockPath);
            /**
             * @var null|array{packages:array<array{name:string, type?:string, require?:array<string,string>, autoload?:AutoloadKeyArray}>} $composerLockJsonArray
             */
            $composerLockJsonArray           = json_decode($composerLockString, true);

            if (is_null($composerLockJsonArray)) {
                return null;
            }

            /**
             * @var ?ComposerJsonArray $requiredPackageComposerJson
             */
            $requiredPackageComposerJson = null;
            foreach ($composerLockJsonArray['packages'] as $packageJson) {
                if ($requiredPackageName === $packageJson['name']) {
                    $requiredPackageComposerJson = $packageJson;
                    break;
                }
            }

            if (is_null($requiredPackageComposerJson)) {
                // e.g. composer-plugin-api, composer-runtime-api
                $this->logger->info('Skipping ' . $requiredPackageName . ' as it is not in composer.lock');
                return null;
            }

            $this->logger->info(
                'Found {requiredPackageName} in composer.lock.',
                [
                    'requiredPackageName' => $requiredPackageName,
                ]
            );

            if (
                !isset($requiredPackageComposerJson['autoload'])
                && empty($requiredPackageComposerJson['require'])
                && (!isset($requiredPackageComposerJson['type']) || $requiredPackageComposerJson['type'] != 'metapackage')
                && ! $this->filesystem->directoryExists(dirname($packageComposerFile))
            ) {
                // e.g. symfony/polyfill-php72 when installed on PHP 7.2 or later.
                $this->logger->info('Skipping ' . $requiredPackageName . ' as it is has no autoload key (possibly a polyfill unnecessary for this version of PHP).');
                return null;
            }

            $projectVendorAbsoluteDir = $this->config->getAbsoluteVendorDirectory();

            $requiredComposerPackage = ComposerPackage::fromComposerJsonArray($requiredPackageComposerJson, $overrideAutoload);
        }

        if (
            isset($installedJsonPackages[$requiredPackageName])
            && is_null($requiredComposerPackage->getRealPath())
            && $installedJsonPackages[$requiredPackageName]['dist']['type'] === 'path'
        ) {
            $installedPackage = $installedJsonPackages[$requiredPackageName];
            $path = $installedPackage['dist']['url'];

            $packageRealPath = $this->filesystem->normalizePath(
                Platform::realpath(
                    $this->filesystem->makeAbsolute(
                        $this->config->getProjectAbsolutePath() . '/' . $path
                    )
                )
            );
            $requiredComposerPackage->setRealpath(
                $packageRealPath
            );
        }
        unset($installedPackage);
        $requiredComposerPackage->setProjectVendorDirectory(
            $this->filesystem->normalizePath(
                $this->config->getAbsoluteVendorDirectory()
            )
        );

        return $requiredComposerPackage;
    }

    /**
     * Get the recorded files autoloaders.
     *
     * @return array<string, array<string>>
     */
    public function getAllFilesAutoloaders(): array
    {
        $filesAutoloaders = [];
        foreach ($this->flatDependencyArray as $packageName => $composerPackage) {
            if (isset($composerPackage->getAutoload()['files'])) {
                $filesAutoloaders[$packageName] = $composerPackage->getAutoload()['files'];
            }
        }
        return $filesAutoloaders;
    }

    /**
     * Unset PHP, ext-*, ...
     *
     * @param string $requiredPackageName A package name from a `require` list, e.g. `php`, `ext-json` or `psr/log`.
     */
    protected function removeVirtualPackagesFilter(string $requiredPackageName): bool
    {
        return ! (
            ComposerPackage::isPlatformPackageName($requiredPackageName, true)
            || in_array($requiredPackageName, $this->virtualPackages)
        );
    }
}
