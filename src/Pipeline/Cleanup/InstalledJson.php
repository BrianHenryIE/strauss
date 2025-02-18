<?php
/**
 * Currently deletes autoload keys where the files no longer exist at those paths.
 *
 * Should it change "install-path" to the new path?
 */

namespace BrianHenryIE\Strauss\Pipeline\Cleanup;

use BrianHenryIE\Strauss\Config\CleanupConfigInterface;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use Composer\Json\JsonFile;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

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

    /**
     * Composer creates a file `vendor/composer/installed.json` which is uses when running `composer dump-autoload`.
     * When `delete-vendor-packages` or `delete-vendor-files` is true, files and directories which have been deleted
     * must also be removed from `installed.json` or Composer will throw an error.
     *
     * TODO: {@see AutoloadFiles} might be redundant if we run this function and then run `composer dump-autoload`.
     */
    public function cleanupInstalledJson(array $flatDependencyTree, DiscoveredSymbols $discoveredSymbols): void
    {
        $this->logger->info('Cleaning up vendor/composer/installed.json');

        $installedJsonFile = new JsonFile(
            sprintf(
                '%s/composer/installed.json',
                $this->getVendorDirectory()
            )
        );
        if (!$installedJsonFile->exists()) {
            $this->logger->warning('Expected vendor/composer/installed.json does not exist.');
            return;
        }
        $installedJsonArray = $installedJsonFile->read();

        $discoveredNamespaces = $discoveredSymbols->getNamespaces();

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
//                $package['install-path'] = '../../' . $this->config->getTargetDirectory() . $package['install-path'];

                $installedJsonArray['packages'][$key]['install-path'] = str_replace(
                    '../',
                    '/../../' . $this->config->getTargetDirectory(),
                    $package['install-path']
                );
//                $this->logger->info('Package directory does not exist: ' . $packageDir . ', removing autoload key.');
//                unset($installedJsonArray['packages'][$key]['autoload']);
//                continue;
            }

            if (!isset($package['autoload'])) {
                $this->logger->debug('Package has no autoload key: ' . $package['name']);
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
                            // TODO replace $originalNamespace with updated namespace
                            // $installedJsonArray['packages'][$key]['autoload'][$type][$original]

                            // space added here because the `\\` was causing `</info>` to be printed.
                            $this->logger->info('Checking PSR-4 namespace: ' . $originalNamespace . ' ');

                            $trimmedOrigianlNamespace = trim($originalNamespace, '\\');
                            if (isset($discoveredNamespaces[$trimmedOrigianlNamespace])) {
                                $namespaceSymbol = $discoveredNamespaces[$trimmedOrigianlNamespace];
                            } else {
                                continue;
                            }

                            // Update the namespace if it has changed.
                            // TODO log if/else.
                            $autoload_key[$type][$namespaceSymbol->getReplacement()] = $autoload_key[$type][$originalNamespace];
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
                         * Also, but not really relevant:
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

        $installedJsonFile->write($installedJsonArray);
    }
}
