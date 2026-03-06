<?php
/**
 * Changes "install-path" to point to vendor-prefixed target directory.
 *
 * * create new vendor-prefixed/composer/installed.json file with copied packages
 * * when delete is enabled, update package paths in the original vendor/composer/installed.json
 * * when delete is enabled, remove dead entries in the original vendor/composer/installed.json
 * * update psr-0 autoload keys to have matching classmap entries
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
use Exception;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Seld\JsonLint\ParsingException;

/**
 * @phpstan-type InstalledJsonPackageSourceArray array{type:string, url:string, reference:string}
 * @phpstan-type InstalledJsonPackageDistArray array{type:string, url:string, reference:string, shasum:string}
 * @phpstan-type InstalledJsonPackageAutoloadPsr0Array array<string,string|array<string>>
 * @phpstan-type InstalledJsonPackageAutoloadPsr4Array array<string,string|array<string>>
 * @phpstan-type InstalledJsonPackageAutoloadClassmapArray string[]
 * @phpstan-type InstalledJsonPackageAutoloadFilesArray string[]
 * @phpstan-type InstalledJsonPackageAutoloadArray array{"psr-4"?:InstalledJsonPackageAutoloadPsr4Array, classmap?:InstalledJsonPackageAutoloadClassmapArray, files?:InstalledJsonPackageAutoloadFilesArray, "psr-0"?:InstalledJsonPackageAutoloadPsr0Array}
 * @phpstan-type InstalledJsonPackageAuthorArray array{name:string,email:string}
 * @phpstan-type InstalledJsonPackageSupportArray array{issues:string, source:string}
 *
 * @phpstan-type InstalledJsonPackageArray array{name:string, version:string, version_normalized:string, source:InstalledJsonPackageSourceArray, dist:InstalledJsonPackageDistArray, require:array<string,string>, require-dev:array<string,string>, time:string, type:string, installation-source:string, autoload?:InstalledJsonPackageAutoloadArray, notification-url:string, license:array<string>, authors:array<InstalledJsonPackageAuthorArray>, description:string, homepage:string, keywords:array<string>, support:InstalledJsonPackageSupportArray, install-path:string}
 *
 * @phpstan-type InstalledJsonArray array{packages:array<InstalledJsonPackageArray>, dev?:bool, dev-package-names:array<string>}
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

    /**
     * @throws FilesystemException
     */
    public function copyInstalledJson(): void
    {
        $source = $this->config->getVendorDirectory() . 'composer/installed.json';
        $target = $this->config->getTargetDirectory() . 'composer/installed.json';

        $this->logger->info('Copying {sourcePath} to {targetPath}', [
            'sourcePath' => $source,
            'targetPath' => $target
        ]);

        $this->filesystem->copy(
            $source,
            $target
        );

        $this->logger->info('Copied {sourcePath} to {targetPath}', [
            'sourcePath' => $source,
            'targetPath' => $target
        ]);

        $this->logger->debug($this->filesystem->read($this->config->getTargetDirectory() . 'composer/installed.json'));
    }

    /**
     * @throws JsonValidationException
     * @throws ParsingException
     * @throws Exception
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
            $this->logger->error(
                'Expected {installedJsonFilePath} does not exist.',
                ['installedJsonFilePath' => $installedJsonFile->getPath()]
            );
            throw new Exception('Expected vendor/composer/installed.json does not exist.');
        }

        $installedJsonFile->validateSchema(JsonFile::LAX_SCHEMA);

        $this->logger->info('Loaded file: {installedJsonFilePath}', ['installedJsonFilePath' => $installedJsonFile->getPath()]);

        return $installedJsonFile;
    }

    /**
     * @param InstalledJsonArray $installedJsonArray
     * @param array<string,ComposerPackage> $flatDependencyTree
     * @return InstalledJsonArray
     */
    protected function updatePackagePaths(array $installedJsonArray, array $flatDependencyTree, string $path): array
    {

        foreach ($installedJsonArray['packages'] as $key => $package) {
            if (in_array($package['name'], $this->config->getExcludePackagesFromCopy(), true)) {
                unset($installedJsonArray['packages'][$key]);
                continue;
            }

            // Skip packages that were never copied in the first place.
            if (!in_array($package['name'], array_keys($flatDependencyTree))) {
                $this->logger->debug('Skipping package: ' . $package['name']);
                continue;
            }
            $this->logger->info('Checking package: ' . $package['name']);

            // `composer/` is here because the install-path is relative to the `vendor/composer` directory.
            $packageDir = $path . 'composer/' . $package['install-path'] . '/';
            if (!$this->filesystem->directoryExists($packageDir)) {
                $this->logger->debug('Package directory does not exist at : ' . $packageDir);

                $newInstallPath = $path . str_replace('../', '', $package['install-path']);

                if (!$this->filesystem->directoryExists($newInstallPath)) {
                    unset($installedJsonArray['packages'][$key]);
                    $this->logger->info('Package directory does not exist: ' . $newInstallPath);
                    continue;
                }

                $newRelativePath = $this->filesystem->getRelativePath(
                    $path . 'composer/',
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
     * @param InstalledJsonPackageArray $packageArray
     * @throws FilesystemException
     */
    protected function pathExistsInPackage(string $vendorDir, array $packageArray, string $relativePath): bool
    {
        return $this->filesystem->exists(
            $vendorDir . 'composer/' . $packageArray['install-path'] . '/' . $relativePath
        );
    }

    /**
     * Remove autoload key entries from `installed.json` whose file or directory does not exist after deleting.
     *
     * @param InstalledJsonArray $installedJsonArray
     * @return InstalledJsonArray
     * @throws FilesystemException
     */
    protected function removeMissingAutoloadKeyPaths(array $installedJsonArray, string $vendorDir, string $installedJsonPath): array
    {
        foreach ($installedJsonArray['packages'] as $packageIndex => $packageArray) {
            if (!isset($packageArray['autoload'])) {
                $this->logger->info(
                    'Package {packageName} has no autoload key in {installedJsonPath}',
                    ['packageName' => $packageArray['name'],'installedJsonPath'=>$installedJsonPath]
                );
                continue;
            }
            // delete_vendor_files
            $path = $vendorDir . 'composer/' . $packageArray['install-path'];
            $pathExists = $this->filesystem->directoryExists($path);
            // delete_vendor_packages
            if (!$pathExists) {
                $this->logger->info(
                    'Removing package autoload key from {installedJsonPath}: {packageName}',
                    ['packageName' => $packageArray['name'],'installedJsonPath'=>$installedJsonPath]
                );
                $installedJsonArray['packages'][$packageIndex]['autoload'] = [];
            }
            foreach ($installedJsonArray['packages'][$packageIndex]['autoload'] ?? [] as $type => $autoload) {
                switch ($type) {
                    case 'files':
                    case 'classmap':
                        // Ensure we filter the current autoload bucket and keep only existing paths
                        $filtered = array_filter(
                            (array) $autoload,
                            function ($relativePath) use ($vendorDir, $packageArray): bool {
                                return is_string($relativePath) && $this->pathExistsInPackage($vendorDir, $packageArray, $relativePath);
                            }
                        );
                        // Reindex to produce a clean list of strings
                        $installedJsonArray['packages'][$packageIndex]['autoload'][$type] = array_values($filtered);
                        break;
                    case 'psr-0':
                    case 'psr-4':
                        foreach ($autoload as $namespace => $paths) {
                            switch (true) {
                                case is_array($paths):
                                    // e.g. [ 'psr-4' => [ 'BrianHenryIE\Project' => ['src','lib] ] ]
                                    $validPaths = [];
                                    foreach ($paths as $path) {
                                        if ($this->pathExistsInPackage($vendorDir, $packageArray, $path)) {
                                            $validPaths[] = $path;
                                        } else {
                                            $this->logger->debug('Removing non-existent path from autoload: ' . $path);
                                        }
                                    }
                                    if (!empty($validPaths)) {
                                        $installedJsonArray['packages'][$packageIndex]['autoload'][$type][$namespace] = $validPaths;
                                    } else {
                                        $this->logger->debug('Removing autoload key: ' . $type);
                                        unset($installedJsonArray['packages'][$packageIndex]['autoload'][$type][$namespace]);
                                    }
                                    break;
                                case is_string($paths):
                                    // e.g. [ 'psr-4' => [ 'BrianHenryIE\Project' => 'src' ] ]
                                    if (!$this->pathExistsInPackage($vendorDir, $packageArray, $paths)) {
                                        $this->logger->debug('Removing autoload key: ' . $type . ' for ' . $paths);
                                        unset($installedJsonArray['packages'][$packageIndex]['autoload'][$type][$namespace]);
                                    }
                                    break;
                                default:
                                    $this->logger->warning('Unexpectedly got neither a string nor array for autoload key in installed.json: ' . $type . ' ' . json_encode($paths));
                                    break;
                            }
                        }
                        break;
                    case 'exclude-from-classmap':
                        break;
                    default:
                        $this->logger->warning(
                            'Unexpected autoload type in {installedJsonPath}: {type}',
                            ['installedJsonPath'=>$installedJsonPath,'type'=>$type]
                        );
                        break;
                }
            }
        }
        /** @var InstalledJsonArray $installedJsonArray */
        $installedJsonArray = $installedJsonArray;
        return $installedJsonArray;
    }

    /**
     * Remove the autoload key for packages from `installed.json` whose target directory does not exist after deleting.
     *
     * E.g. after the file is copied to the target directory, this will remove dev dependencies and unmodified dependencies from the second installed.json
     *
     * @param InstalledJsonArray $installedJsonArray
     * @param array<string,ComposerPackage> $flatDependencyTree
     * @return InstalledJsonArray
     */
    protected function removeMovedPackagesAutoloadKeyFromVendorDirInstalledJson(array $installedJsonArray, array $flatDependencyTree, string $installedJsonPath): array
    {
        /**
         * @var int $key
         * @var InstalledJsonPackageArray $packageArray
         */
        foreach ($installedJsonArray['packages'] as $key => $packageArray) {
            $packageName = $packageArray['name'];
            $package = $flatDependencyTree[$packageName] ?? null;
            if (!$package) {
                // Probably a dev dependency that we aren't tracking.
                continue;
            }

            if ($package->didDelete()) {
                $this->logger->info(
                    'Removing deleted package autoload key from {installedJsonPath}: {packageName}',
                    ['installedJsonPath' => $installedJsonPath, 'packageName' => $packageName]
                );
                $installedJsonArray['packages'][$key]['autoload'] = [];
            }
        }
        return $installedJsonArray;
    }

    /**
     * Remove the autoload key for packages from `vendor-prefixed/composer/installed.json` whose target directory does not exist in `vendor-prefixed`.
     *
     * E.g. after the file is copied to the target directory, this will remove dev dependencies and unmodified dependencies from the second installed.json
     *
     * @param InstalledJsonArray $installedJsonArray
     * @param array<string,ComposerPackage> $flatDependencyTree
     * @return InstalledJsonArray
     */
    protected function removeMovedPackagesAutoloadKeyFromTargetDirInstalledJson(array $installedJsonArray, array $flatDependencyTree, string $installedJsonPath): array
    {
        /**
         * @var int $key
         * @var InstalledJsonPackageArray $packageArray
         */
        foreach ($installedJsonArray['packages'] as $key => $packageArray) {
            $packageName = $packageArray['name'];

            $remove = false;

            if (!in_array($packageName, array_keys($flatDependencyTree))) {
                // If it's not a package we were ever considering copying, then we can remove it.
                $remove = true;
            } else {
                $package = $flatDependencyTree[$packageName] ?? null;
                if (!$package) {
                    // Probably a dev dependency.
                    continue;
                }
                if (!$package->didCopy()) {
                    // If it was marked not to copy, then we know it's not in the vendor-prefixed directory, and we can remove it.
                    $remove = true;
                }
            }

            if ($remove) {
                $this->logger->info(
                    'Removing deleted package autoload key from {installedJsonPath}: {packageName}',
                    ['installedJsonPath' => $installedJsonPath, 'packageName' => $packageName]
                );
                $installedJsonArray['packages'][$key]['autoload'] = [];
            }
        }
        return $installedJsonArray;
    }

    /**
     * @param InstalledJsonArray $installedJsonArray
     * @return InstalledJsonArray
     */
    protected function updateNamespaces(array $installedJsonArray, DiscoveredSymbols $discoveredSymbols): array
    {
        $discoveredNamespaces = $discoveredSymbols->getNamespaces();

        foreach ($installedJsonArray['packages'] as $key => $package) {
            if (!isset($package['autoload'])) {
                // woocommerce/action-scheduler
                $this->logger->info('Package has no autoload key: ' . $package['name'] . ' ' . $package['type']);
                continue;
            }

            $autoload_key = $package['autoload'];
            if (!isset($autoload_key['classmap'])) {
                $autoload_key['classmap'] = [];
            }
            foreach ($autoload_key as $type => $autoload) {
                switch ($type) {
                    case 'psr-0':
                        /** @var string $relativePath */
                        foreach (array_values((array) $autoload_key[$type]) as $relativePath) {
                            $packageRelativePath = $package['install-path'];
                            if (1 === preg_match('#.*'.preg_quote($this->filesystem->normalize($this->config->getTargetDirectory()), '#').'/(.*)#', $packageRelativePath, $matches)) {
                                $packageRelativePath = $matches[1];
                            }
                            // Convert psr-0 autoloading to classmap autoloading
                            if ($this->filesystem->directoryExists($this->config->getTargetDirectory() . 'composer/' . $packageRelativePath . $relativePath)) {
                                $autoload_key['classmap'][] = $relativePath;
                            }
                        }
                        // Intentionally fall through
                        // Although the PSR-0 implementation here is a bit of a hack.
                    case 'psr-4':
                        /**
                         * e.g.
                         * * {"psr-4":{"Psr\\Log\\":"Psr\/Log\/"}}
                         * * {"psr-4":{"":"src\/"}}
                         * * {"psr-4":{"Symfony\\Polyfill\\Mbstring\\":""}}
                         * * {"psr-4":{"Another\\Package\\":["src","includes"]}}
                         * * {"psr-0":{"PayPal":"lib\/"}}
                         */
                        foreach ($autoload_key[$type] ?? [] as $originalNamespace => $packageRelativeDirectory) {
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
                            /** @phpstan-ignore offsetAccess.notFound */
                            $autoload_key[$type][str_replace($trimmedOriginalNamespace, $namespaceSymbol->getReplacement(), $originalNamespace)] = $autoload_key[$type][$originalNamespace];
                            unset($autoload_key[$type][$originalNamespace]);
                        }
                        break;
                    default:
                        /**
                         * `files`, `classmap`, `exclude-from-classmap`
                         * These don't contain namespaces in the autoload key.
                         * * {"classmap":["src\/"]}
                         * * {"files":["src\/functions.php"]}
                         * * {"exclude-from-classmap":["\/Tests\/"]}
                         *
                         * Custom autoloader types might.
                         */
                        if (!in_array($type, ['files', 'classmap', 'exclude-from-classmap'])) {
                            $this->logger->warning('Unexpected autoloader type: {type} in {packageName}.', [
                                'type' => $type, 'packageName' => $package['name']
                            ]);
                        }
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
     * @throws Exception
     * @throws FilesystemException
     */
    public function cleanTargetDirInstalledJson(array $flatDependencyTree, DiscoveredSymbols $discoveredSymbols): void
    {
        $targetDir = $this->config->getTargetDirectory();

        $installedJsonFile = $this->getJsonFile($targetDir);

        /**
         * @var InstalledJsonArray $installedJsonArray
         */
        $installedJsonArray = $installedJsonFile->read();

        $this->logger->debug(
            '{installedJsonFilePath} before: {installedJsonArray}',
            ['installedJsonFilePath' => $installedJsonFile->getPath(), 'installedJsonArray' => json_encode($installedJsonArray)]
        );

        $installedJsonArray = $this->updatePackagePaths($installedJsonArray, $flatDependencyTree, $this->config->getTargetDirectory());

        $installedJsonArray = $this->removeMissingAutoloadKeyPaths($installedJsonArray, $this->config->getTargetDirectory(), $installedJsonFile->getPath());

        $installedJsonArray = $this->removeMovedPackagesAutoloadKeyFromTargetDirInstalledJson(
            $installedJsonArray,
            $flatDependencyTree,
            $installedJsonFile->getPath()
        );

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
     * Composer creates a file `vendor/composer/installed.json` which is used when running `composer dump-autoload`.
     * When `delete-vendor-packages` or `delete-vendor-files` is true, files and directories which have been deleted
     * must also be removed from `installed.json` or Composer will throw an error.
     *
     * @param array<string,ComposerPackage> $flatDependencyTree
     * @throws Exception
     * @throws FilesystemException
     */
    public function cleanupVendorInstalledJson(array $flatDependencyTree, DiscoveredSymbols $discoveredSymbols): void
    {

        $vendorDir = $this->config->getVendorDirectory();

        $vendorInstalledJsonFile = $this->getJsonFile($vendorDir);

        $this->logger->info('Cleaning up {installedJsonPath}', ['installedJsonPath' => $vendorInstalledJsonFile->getPath()]);

        /**
         * @var InstalledJsonArray $installedJsonArray
         */
        $installedJsonArray = $vendorInstalledJsonFile->read();

        $installedJsonArray = $this->removeMissingAutoloadKeyPaths($installedJsonArray, $this->config->getVendorDirectory(), $vendorInstalledJsonFile->getPath());

        $installedJsonArray = $this->removeMovedPackagesAutoloadKeyFromVendorDirInstalledJson($installedJsonArray, $flatDependencyTree, $vendorInstalledJsonFile->getPath());

        $installedJsonArray = $this->updatePackagePaths($installedJsonArray, $flatDependencyTree, $this->config->getVendorDirectory());

        // Only relevant when source = target.
        $installedJsonArray = $this->updateNamespaces($installedJsonArray, $discoveredSymbols);

        $vendorInstalledJsonFile->write($installedJsonArray);
    }
}
