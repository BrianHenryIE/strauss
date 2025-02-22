<?php
/**
 * Changes "install-path" to point to vendor-prefixed target directory.
 *
 * TODO: create new vendor-prefixed/composer/installed.json file with copied packages
 * TODO: when delete is enabled, update package paths in the original vendor/composer/installed.json (~done)
 * TODO: when delete is enabled, remove dead entries in the original vendor/composer/installed.json
 *
 * @see vendor/composer/installed.json
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Pipeline\Cleanup;

use BrianHenryIE\Strauss\Config\CleanupConfigInterface;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use Composer\Json\JsonFile;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * @phpstan-type InstalledJsonPackageArray = array{name:string, version:string, version_normalized:string, source:array, dist:array, require:array, time:string, type:string, installation-source:string, autoload:array, notification-url:string, license:array, authors:array, description:string, homepage:string, keywords:array, support:array, install-path:string}
 * @phpstan-type InstalledJson array{packages:array<InstalledJsonPackageArray>, dev:bool, dev-package-names:array<string>}
 */
class InstalledJson
{
    use LoggerAwareTrait;

    protected string $workingDir;
    protected CleanupConfigInterface $config;
    protected FileSystem $filesystem;

    public function __construct(
        string $workingDir,
        CleanupConfigInterface $config,
        FileSystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->workingDir = $workingDir;
        $this->config = $config;
        $this->filesystem = $filesystem;

        $this->setLogger($logger);
    }

    protected function getVendorDirectory(): string
    {
        return $this->config->isDryRun()
            ? 'mem:/' . $this->workingDir . $this->config->getVendorDirectory()
            : $this->workingDir . $this->config->getVendorDirectory();
    }

    protected function getTargetDirectory(): string
    {
        return $this->config->isDryRun()
            ? 'mem:/' . $this->workingDir . $this->config->getTargetDirectory()
            : $this->workingDir . $this->config->getTargetDirectory();
    }

    protected function copyInstalledJson(): void
    {
        $this->logger->info('Copying vendor/composer/installed.json to vendor-prefixed/composer/installed.json');

        $this->filesystem->copy(
            $this->getVendorDirectory() . 'composer/installed.json',
            $this->getTargetDirectory() . 'composer/installed.json'
        );
    }

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
            $packageDir = $this->getVendorDirectory() . 'composer/' . $package['install-path'] . '/';
            if (!$this->filesystem->directoryExists($packageDir)) {
                $newInstallPath = $this->getTargetDirectory() . str_replace('../', '', $package['install-path']);

                if (!$this->filesystem->directoryExists($newInstallPath)) {
                    $this->logger->warning('Package directory unexpectedly does not exist: ' . $newInstallPath);
                    continue;
                }

                $newRelativePath = $this->filesystem->getRelativePath(
                    $this->getVendorDirectory() . 'composer/',
                    $newInstallPath
                );

                $installedJsonArray['packages'][$key]['install-path'] = $newRelativePath;
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
            foreach ($autoload_key as $type => $autoload) {
                switch ($type) {
                    case 'psr-4':
                        /**
                         * e.g.
                         * * {"psr-4":{"Psr\\Log\\":"Psr\/Log\/"}}
                         * * {"psr-4":{"":"src\/"}}
                         * * {"psr-4":{"Symfony\\Polyfill\\Mbstring\\":""}}
                         */
                        foreach ($autoload_key[$type] as $originalNamespace => $packageRelativeDirectory) {
                            // Replace $originalNamespace with updated namespace

                            // Just for dev – find a package like this and write a test for it.
                            if (empty($originalNamespace)) {
                                // nesbot/carbon
                                continue;
                                throw new \Exception($package['name']);
                            }

                            $trimmedOriginalNamespace = trim($originalNamespace, '\\');

                            $this->logger->info('Checking PSR-4 namespace: ' . $trimmedOriginalNamespace);

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
                         * * {"psr-0":{"PayPal":"lib\/"}}
                         * * {"files":["src\/functions.php"]}
                         *
                         * Also:
                         * * {"exclude-from-classmap":["\/Tests\/"]}
                         */

//                        $autoload_key[$type] = array_filter($autoload, function ($file) use ($packageDir) {
//                            $filename = $packageDir . DIRECTORY_SEPARATOR . $file;
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
     * @param array $flatDependencyTree
     * @param DiscoveredSymbols $discoveredSymbols
     */
    public function createAndCleanTargetDirInstalledJson(array $flatDependencyTree, DiscoveredSymbols $discoveredSymbols): void
    {
        $this->copyInstalledJson();

        $vendorDir = $this->getTargetDirectory();

        $installedJsonFile = $this->getJsonFile($vendorDir);

        /**
         * @var InstalledJson $installedJsonArray
         */
        $installedJsonArray = $installedJsonFile->read();

        $installedJsonArray = $this->updatePackagePaths($installedJsonArray, $flatDependencyTree);

        $installedJsonArray = $this->removeMissingPackages($installedJsonArray, $vendorDir);

        $installedJsonArray = $this->updateNamespaces($installedJsonArray, $discoveredSymbols);

        foreach ($installedJsonArray['packages'] as $index => $package) {
            if (!in_array($package['name'], array_keys($flatDependencyTree))) {
                unset($installedJsonArray['packages'][$index]);
            }
        }
        $installedJsonArray['dev'] = false;
        $installedJsonArray['dev-package-names'] = [];

        $installedJsonFile->write($installedJsonArray);
    }


    /**
     * Composer creates a file `vendor/composer/installed.json` which is uses when running `composer dump-autoload`.
     * When `delete-vendor-packages` or `delete-vendor-files` is true, files and directories which have been deleted
     * must also be removed from `installed.json` or Composer will throw an error.
     *
     * TODO: {@see AutoloadFiles} might be redundant if we run this function and then run `composer dump-autoload`.
     */
    public function cleanupVendorInstalledJson(array $flatDependencyTree, DiscoveredSymbols $discoveredSymbols): void
    {
        $this->logger->info('Cleaning up installed.json');

        $vendorDir = $this->getVendorDirectory();

        $installedJsonFile = $this->getJsonFile($vendorDir);

        /**
         * @var InstalledJson $installedJsonArray
         */
        $installedJsonArray = $installedJsonFile->read();

        $installedJsonArray = $this->updatePackagePaths($installedJsonArray, $flatDependencyTree);

        $installedJsonArray = $this->removeMissingPackages($installedJsonArray, $vendorDir);

        $installedJsonArray = $this->updateNamespaces($installedJsonArray, $discoveredSymbols);

        $installedJsonFile->write($installedJsonArray);
    }
}
