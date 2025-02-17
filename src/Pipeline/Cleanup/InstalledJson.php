<?php

namespace BrianHenryIE\Strauss\Pipeline\Cleanup;

use BrianHenryIE\Strauss\Config\CleanupConfigInterface;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use Composer\Json\JsonFile;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class InstalledJson {
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
     * TODO: {@see self::cleanupFilesAutoloader()} might be redundant if we run this function and then run `composer dump-autoload`.
     */
    public function cleanupInstalledJson(): void
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

        foreach ($installedJsonArray['packages'] as $key => $package) {
            if (!isset($package['autoload'])) {
                $this->logger->debug('Package has no autoload key: ' . $package['name']);
                continue;
            }

            $packageDir = $this->getVendorDirectory() . 'composer/' .$package['install-path'] . '/';
            if (!$this->filesystem->directoryExists($packageDir)) {
                $this->logger->debug('Package directory does not exist: ' . $packageDir);
                continue;
            }
            $autoload_key = $package['autoload'];
            foreach ($autoload_key as $type => $autoload) {
                switch ($type) {
                    case 'psr-4':
                        foreach ($autoload_key[$type] as $namespace => $dirs) {
                            if (is_array($dirs)) {
                                $autoload_key[$type][$namespace] = array_filter($dirs, function ($dir) use ($packageDir) {
                                    $dir = $packageDir . $dir;
                                    return $this->filesystem->directoryExists($dir) || $this->filesystem->fileExists($dir);
                                });
                            } else {
                                $dir = $packageDir . $dirs;
                                if (! ($this->filesystem->directoryExists($dir) || $this->filesystem->fileExists($dir))) {
                                    unset($autoload_key[$type][$namespace]);
                                }
                            }
                        }
                        break;
                    default: // files, classmap, psr-0
                        $autoload_key[$type] = array_filter($autoload, function ($file) use ($packageDir) {
                            $filename = $packageDir . DIRECTORY_SEPARATOR . $file;
                            return $this->filesystem->directoryExists($filename) || $this->filesystem->fileExists($filename);
                        });
                        break;
                }
            }
            $installedJsonArray['packages'][$key]['autoload'] = array_filter($autoload_key);
        }

        $installedJsonFile->write($installedJsonArray);
    }
}