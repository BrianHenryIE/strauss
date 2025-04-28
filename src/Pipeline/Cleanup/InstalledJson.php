<?php
/**
 * Changes "install-path" to point to vendor-prefixed target directory.
 *
 * * create new vendor-prefixed/composer/installed.json file with copied packages
 * * when delete is enabled, update package paths in the original vendor/composer/installed.json
 * * when delete is enabled, remove dead entries in the original vendor/composer/installed.jso
 *
 * @see vendor/composer/installed.json
 *
 * TODO: when delete_vendor_files is used, the original directory still exists so the paths are not updated.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Pipeline\Cleanup;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\CleanupConfigInterface;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use Composer\Json\JsonFile;
use Composer\Json\JsonValidationException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Seld\JsonLint\ParsingException;

/**
 * @phpstan-type InstalledJsonPackageSourceArray array{type:string, url:string, reference:string}
 * @phpstan-type InstalledJsonPackageDistArray array{type:string, url:string, reference:string, shasum:string}
 * @phpstan-type InstalledJsonPackageAutoloadArray array<string,array<string,string>>
 * @phpstan-type InstalledJsonPackageAuthorArray array{name:string,email:string}
 * @phpstan-type InstalledJsonPackageSupportArray array{issues:string, source:string}
 *
 * @phpstan-type InstalledJsonPackageArray array{name:string, version:string, version_normalized:string, source:InstalledJsonPackageSourceArray, dist:InstalledJsonPackageDistArray, require:array<string,string>, require-dev:array<string,string>, time:string, type:string, installation-source:string, autoload:InstalledJsonPackageAutoloadArray, notification-url:string, license:array<string>, authors:array<InstalledJsonPackageAuthorArray>, description:string, homepage:string, keywords:array<string>, support:InstalledJsonPackageSupportArray, install-path:string}
 *
 * @phpstan-type InstalledJsonArray array{packages:array<InstalledJsonPackageArray>, dev:bool, dev-package-names:array<string>}
 */
class InstalledJson
{
    use LoggerAwareTrait;

    protected CleanupConfigInterface $config;

    protected FileSystem $filesystem;

    public function __construct(
        CleanupConfigInterface $config,
        FileSystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->filesystem = $filesystem;

        $this->setLogger($logger);
    }

    protected function copyInstalledJson(): void
    {
        $this->logger->info('Copying vendor/composer/installed.json to vendor-prefixed/composer/installed.json');

        $this->filesystem->copy(
            $this->config->getVendorDirectory() . 'composer/installed.json',
            $this->config->getTargetDirectory() . 'composer/installed.json'
        );

        $this->logger->debug('Copied vendor/composer/installed.json to vendor-prefixed/composer/installed.json');
        $this->logger->debug($this->filesystem->read($this->config->getTargetDirectory() . 'composer/installed.json'));
    }

    /**
     * @throws JsonValidationException
     * @throws ParsingException
     */
    protected function getJsonFile(string $vendorDir): JsonFile
    {
        $installedJsonFile = new JsonFile(
            sprintf(
                '%scomposer/installed.json',
                $vendorDir
            )
        );
        if (!$installedJsonFile->exists()) {
            $this->logger->error('Expected vendor/composer/installed.json does not exist.');
            throw new \Exception('Expected vendor/composer/installed.json does not exist.');
        }

        $installedJsonFile->validateSchema(JsonFile::LAX_SCHEMA);

        $this->logger->info('Loaded installed.json file: ' . $installedJsonFile->getPath());

        return $installedJsonFile;
    }

    /**
     * @param InstalledJsonArray $installedJsonArray
     * @param array<string,ComposerPackage> $flatDependencyTree
     */
    protected function updatePackagePaths(array $installedJsonArray, array $flatDependencyTree): array
    {

        foreach ($installedJsonArray['packages'] as $key => $package) {
            // Skip packages that were never copied in the first place.
            if (!in_array($package['name'], array_keys($flatDependencyTree))) {
                $this->logger->debug('Skipping package: ' . $package['name']);
                continue;
            }
            $this->logger->info('Checking package: ' . $package['name']);

            // `composer/` is here because the install-path is relative to the `vendor/composer` directory.
            $packageDir = $this->config->getVendorDirectory() . 'composer/' . $package['install-path'] . '/';
            if (!$this->filesystem->directoryExists($packageDir)) {
                $this->logger->debug('Original package directory does not exist at : ' . $packageDir);

                $newInstallPath = $this->filesystem->normalize($this->config->getTargetDirectory() . '/composer/' .$package['install-path']);

                if (!$this->filesystem->directoryExists($newInstallPath)) {
                    $this->logger->warning('Target package directory unexpectedly DOES NOT exist: ' . $newInstallPath);
                    continue;
                }

                $newRelativePath = $this->filesystem->getRelativePath(
                    $this->config->getVendorDirectory() . 'composer/',
                    $newInstallPath
                );

                $installedJsonArray['packages'][$key]['install-path'] = $newRelativePath;
            } else {
                $this->logger->debug('Original package directory exists at : ' . $packageDir);
            }
        }
        return $installedJsonArray;
    }


    /**
     * Remove packages from `installed.json` whose target directory does not exist
     *
     * E.g. after the file is copied to the target directory, this will remove dev dependencies and unmodified dependencies from the second installed.json
     */
    protected function removeMissingPackages(array $installedJsonArray, string $vendorDir): array
    {
        foreach ($installedJsonArray['packages'] as $key => $package) {
            $path = $vendorDir . 'composer/' . $package['install-path'];
            $pathExists = $this->filesystem->directoryExists($path);
            if (!$pathExists) {
                $this->logger->info('Removing package from installed.json: ' . $package['name']);
                unset($installedJsonArray['packages'][$key]);
            }
        }
        return $installedJsonArray;
    }


    protected function updateNamespaces(array $installedJsonArray, DiscoveredSymbols $discoveredSymbols): array
    {
        $discoveredNamespaces = $discoveredSymbols->getNamespaces();

        foreach ($installedJsonArray['packages'] as $key => $package) {
            if (!isset($package['autoload'])) {
                // woocommerce/action-scheduler
                $this->logger->debug('Package has no autoload key: ' . $package['name'] . ' ' . $package['type']);
                continue;
            }

            $autoload_key = $package['autoload'];
            if (!isset($autoload_key['classmap'])) {
                $autoload_key['classmap'] = [];
            }
            foreach ($autoload_key as $type => $autoload) {
                switch ($type) {
                    case 'psr-0':
                        foreach (array_values((array) $autoload_key['psr-0']) as $relativePath) {
                            $packageRelativePath = $package['install-path'];
                            if (1 === preg_match('#.*'.preg_quote($this->filesystem->normalize($this->config->getTargetDirectory()), '#').'/(.*)#', $packageRelativePath, $matches)) {
                                $packageRelativePath = $matches[1];
                            }
                            if ($this->filesystem->directoryExists($this->config->getTargetDirectory() . $packageRelativePath . $relativePath)) {
                                $autoload_key['classmap'][] = $relativePath;
                            }
                        }
                        // Intentionally fall through
                        // Although the PSR-0 implemention here is a bit of a hack.
                    case 'psr-4':
                        /**
                         * e.g.
                         * * {"psr-4":{"Psr\\Log\\":"Psr\/Log\/"}}
                         * * {"psr-4":{"":"src\/"}}
                         * * {"psr-4":{"Symfony\\Polyfill\\Mbstring\\":""}}
                         * * {"psr-0":{"PayPal":"lib\/"}}
                         */
                        foreach ($autoload_key[$type] as $originalNamespace => $packageRelativeDirectory) {
                            // Replace $originalNamespace with updated namespace

                            // Just for dev – find a package like this and write a test for it.
                            if (empty($originalNamespace)) {
                                // In the case of `nesbot/carbon`, it uses an empty namespace but the classes are in the `Carbon`
                                // namespace, so using `override_autoload` should be a good solution if this proves to be an issue.
                                // The package directory will be updated, so for whatever reason the original empty namespace
                                // works, maybe the updated namespace will work too.
                                $this->logger->warning('Empty namespace found in autoload. Behaviour is not fully documented: ' . $package['name']);
                                continue;
                            }

                            $trimmedOriginalNamespace = trim($originalNamespace, '\\');

                            $this->logger->info('Checking '.$type.' namespace: ' . $trimmedOriginalNamespace);

                            if (isset($discoveredNamespaces[$trimmedOriginalNamespace])) {
                                $namespaceSymbol = $discoveredNamespaces[$trimmedOriginalNamespace];
                            } else {
                                $this->logger->debug('Namespace not found in list of changes: ' . $trimmedOriginalNamespace);
                                continue;
                            }

                            if ($trimmedOriginalNamespace === trim($namespaceSymbol->getReplacement(), '\\')) {
                                $this->logger->debug('Namespace is unchanged: ' . $trimmedOriginalNamespace);
                                continue;
                            }

                            // Update the namespace if it has changed.
                            $this->logger->info('Updating namespace: ' . $trimmedOriginalNamespace . ' => ' . $namespaceSymbol->getReplacement());
                            $autoload_key[$type][str_replace($trimmedOriginalNamespace, $namespaceSymbol->getReplacement(), $originalNamespace)] = $autoload_key[$type][$originalNamespace];
                            unset($autoload_key[$type][$originalNamespace]);

//                            if (is_array($packageRelativeDirectory)) {
//                                $autoload_key[$type][$originalNamespace] = array_filter(
//                                    $packageRelativeDirectory,
//                                    function ($dir) use ($packageDir) {
//                                                $dir = $packageDir . $dir;
//                                                $exists = $this->filesystem->directoryExists($dir) || $this->filesystem->fileExists($dir);
//                                        if (!$exists) {
//                                            $this->logger->info('Removing non-existent directory from autoload: ' . $dir);
//                                        } else {
//                                            $this->logger->debug('Keeping directory in autoload: ' . $dir);
//                                        }
//                                        return $exists;
//                                    }
//                                );
//                            } else {
//                                $dir = $packageDir . $packageRelativeDirectory;
//                                if (! ($this->filesystem->directoryExists($dir) || $this->filesystem->fileExists($dir))) {
//                                    $this->logger->info('Removing non-existent directory from autoload: ' . $dir);
//                                    // /../../../vendor-prefixed/lib
//                                    unset($autoload_key[$type][$originalNamespace]);
//                                } else {
//                                    $this->logger->debug('Keeping directory in autoload: ' . $dir);
//                                }
//                            }
                        }
                        break;
                    default: // files, classmap, psr-0
                        /**
                         * E.g.
                         *
                         * * {"classmap":["src\/"]}
                         * * {"files":["src\/functions.php"]}
                         *
                         * Also:
                         * * {"exclude-from-classmap":["\/Tests\/"]}
                         */

//                        $autoload_key[$type] = array_filter($autoload, function ($file) use ($packageDir) {
//                            $filename = $packageDir . '/' . $file;
//                            $exists = $this->filesystem->directoryExists($filename) || $this->filesystem->fileExists($filename);
//                            if (!$exists) {
//                                $this->logger->info('Removing non-existent file from autoload: ' . $filename);
//                            } else {
//                                $this->logger->debug('Keeping file in autoload: ' . $filename);
//                            }
//                        });
                        break;
                }
            }
            $installedJsonArray['packages'][$key]['autoload'] = array_filter($autoload_key);
        }

        return $installedJsonArray;
    }
    /**
     * @param array<string,ComposerPackage> $flatDependencyTree
     * @param DiscoveredSymbols $discoveredSymbols
     */
    public function createAndCleanTargetDirInstalledJson(array $flatDependencyTree, DiscoveredSymbols $discoveredSymbols): void
    {
        $this->copyInstalledJson();

        $targetDir = $this->config->getTargetDirectory();

        $installedJsonFile = $this->getJsonFile($targetDir);

        /**
         * @var InstalledJsonArray $installedJsonArray
         */
        $installedJsonArray = $installedJsonFile->read();

        $this->logger->debug('Installed.json before: ' . json_encode($installedJsonArray));

        $installedJsonArray = $this->updatePackagePaths($installedJsonArray, $flatDependencyTree);

        $installedJsonArray = $this->removeMissingPackages($installedJsonArray, $targetDir);

        $installedJsonArray = $this->updateNamespaces($installedJsonArray, $discoveredSymbols);

        foreach ($installedJsonArray['packages'] as $index => $package) {
            if (!in_array($package['name'], array_keys($flatDependencyTree))) {
                unset($installedJsonArray['packages'][$index]);
            }
        }
        $installedJsonArray['dev'] = false;
        $installedJsonArray['dev-package-names'] = [];

        $this->logger->debug('Installed.json after: ' . json_encode($installedJsonArray));

        $this->logger->info('Writing installed.json to ' . $targetDir);

        $installedJsonFile->write($installedJsonArray);

        $this->logger->info('Installed.json written to ' . $targetDir);
    }


    /**
     * Composer creates a file `vendor/composer/installed.json` which is uses when running `composer dump-autoload`.
     * When `delete-vendor-packages` or `delete-vendor-files` is true, files and directories which have been deleted
     * must also be removed from `installed.json` or Composer will throw an error.
     *
     * @param array<string,ComposerPackage> $flatDependencyTree
     */
    public function cleanupVendorInstalledJson(array $flatDependencyTree, DiscoveredSymbols $discoveredSymbols): void
    {
        $this->logger->info('Cleaning up installed.json');

        $vendorDir = $this->config->getVendorDirectory();

        $vendorInstalledJsonFile = $this->getJsonFile($vendorDir);

        /**
         * @var InstalledJsonArray $installedJsonArray
         */
        $installedJsonArray = $vendorInstalledJsonFile->read();

        $installedJsonArray = $this->updatePackagePaths($installedJsonArray, $flatDependencyTree);

        $installedJsonArray = $this->updateNamespaces($installedJsonArray, $discoveredSymbols);

        $installedJsonArray = $this->removeMissingPackages($installedJsonArray, $vendorDir);

        $vendorInstalledJsonFile->write($installedJsonArray);
    }
}
