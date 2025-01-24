<?php
/**
 * Deletes source files and empty directories.
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Config\CleanupConfigInterface;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use Composer\Json\JsonFile;
use FilesystemIterator;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Cleanup
{
    use LoggerAwareTrait;

    protected Filesystem $filesystem;

    protected string $workingDir;

    protected bool $isDeleteVendorFiles;
    protected bool $isDeleteVendorPackages;

    protected string $vendorDirectory = 'vendor'. DIRECTORY_SEPARATOR;
    protected string $targetDirectory;

    protected CleanupConfigInterface $config;

    public function __construct(
        CleanupConfigInterface $config,
        string $workingDir,
        Filesystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;

        $this->vendorDirectory = $config->getVendorDirectory();
        $this->targetDirectory = $config->getTargetDirectory();
        $this->workingDir = $workingDir;

        $this->isDeleteVendorFiles = $config->isDeleteVendorFiles() && $config->getTargetDirectory() !== $config->getVendorDirectory();
        $this->isDeleteVendorPackages = $config->isDeleteVendorPackages() && $config->getTargetDirectory() !== $config->getVendorDirectory();

        $this->filesystem = $filesystem;
    }

    /**
     * Maybe delete the source files that were copied (depending on config),
     * then delete empty directories.
     *
     * @param File[] $files
     *
     * @throws FilesystemException
     */
    public function cleanup(array $files): void
    {
        if (!$this->isDeleteVendorPackages && !$this->isDeleteVendorFiles) {
            return;
        }

        $sourceFiles = array_map(
            fn($file) => $file->getSourcePath($this->workingDir . $this->config->getVendorDirectory()),
            $files
        );

        if ($this->isDeleteVendorPackages) {
            $packages = [];
            foreach ($files as $file) {
                if ($file instanceof FileWithDependency) {
                    $packages[$file->getDependency()->getPackageName()] = $file->getDependency();
                }
            }

            /** @var ComposerPackage $package */
            foreach ($packages as $package) {
                // Normal package.
                if (str_starts_with($package->getPackageAbsolutePath(), $this->workingDir)) {
                    $packageRelativePath = str_replace($this->workingDir, '', $package->getPackageAbsolutePath());

                    if ($this->config->isDryRun()) {
                        $this->logger->info('Would delete ' . $packageRelativePath);
                        continue;
                    }

                    $this->filesystem->deleteDirectory($package->getPackageAbsolutePath());
                } else {
                    if ($this->config->isDryRun()) {
                        // TODO: log _where_ the symlink is pointing to.
                        $this->logger->info('Would remove symlink at ' . $package->getRelativePath());
                        continue;
                    }

                    // If it's a symlink, remove the symlink in the directory
                    $symlinkPath =
                        rtrim(
                            $this->workingDir . $this->config->getVendorDirectory() . $package->getRelativePath(),
                            '/'
                        );

                    if (false !== strpos('WIN', PHP_OS)) {
                        /**
                         * `unlink()` will not work on Windows. `rmdir()` will not work if there are files in the directory.
                         * "On windows, take care that `is_link()` returns false for Junctions."
                         *
                         * @see https://www.php.net/manual/en/function.is-link.php#113263
                         * @see https://stackoverflow.com/a/18262809/336146
                         */
                        rmdir($symlinkPath);
                    } else {
                        unlink($symlinkPath);
                    }
                }
            }
        } elseif ($this->isDeleteVendorFiles) {
            foreach ($files as $file) {
                if (!$file->isDoDelete()) {
                    continue;
                }

                $sourceRelativePath = $file->getSourcePath($this->workingDir);

                if ($this->config->isDryRun()) {
                    $this->logger->info('Would delete ' . $sourceRelativePath);
                    continue;
                }

                $this->filesystem->delete($file->getSourcePath());

                $file->setDidDelete(true);
            }

            $this->cleanupFilesAutoloader();
        }

        // Get the root folders of the moved files.
        $rootSourceDirectories = [];
        foreach ($sourceFiles as $sourceFile) {
            $arr = explode("/", $sourceFile, 2);
            $dir = $arr[0];
            $rootSourceDirectories[ $dir ] = $dir;
        }
        $rootSourceDirectories = array_map(
            function (string $path): string {
                return $this->vendorDirectory . $path;
            },
            array_keys($rootSourceDirectories)
        );

        foreach ($rootSourceDirectories as $rootSourceDirectory) {
            if (!$this->filesystem->isDir($rootSourceDirectory) || is_link($rootSourceDirectory)) {
                continue;
            }

            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $this->workingDir . $rootSourceDirectory,
                    FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($it as $file) {
                if ($file->isDir() && $this->dirIsEmpty((string) $file)) {
                    rmdir((string)$file);
                }
            }
        }

        $this->cleanupInstalledJson();
    }

    // TODO: Use Symfony or Flysystem functions.
    protected function dirIsEmpty(string $dir): bool
    {
        $di = new RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
        return iterator_count($di) === 0;
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
        $installedJsonFile = new JsonFile($this->workingDir . '/vendor/composer/installed.json');
        if (!$installedJsonFile->exists()) {
            return;
        }
        $installedJsonArray = $installedJsonFile->read();

        foreach ($installedJsonArray['packages'] as $key => $package) {
            if (!isset($package['autoload'])) {
                continue;
            }
            // ./pcre i.e. vendor/composer/pcre
            $packageDir = realpath($this->workingDir . $this->vendorDirectory . 'composer/' .$package['install-path']);
            if (!$this->filesystem->isDir($packageDir)) {
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
                                    return is_readable($dir);
                                });
                            } else {
                                $dir = $packageDir . $dirs;
                                if (! is_readable($dir)) {
                                    unset($autoload_key[$type][$namespace]);
                                }
                            }
                        }
                        break;
                    default: // files, classmap, psr-0
                        $autoload_key[$type] = array_filter($autoload, function ($file) use ($packageDir) {
                            $filename = $packageDir . DIRECTORY_SEPARATOR . $file;
                            return $this->filesystem->isDir($filename) || $this->filesystem->fileExists($filename);
                        });
                        break;
                }
            }
            $installedJsonArray['packages'][$key]['autoload'] = array_filter($autoload_key);
        }
        $installedJsonFile->write($installedJsonArray);
    }

    /**
     * After files are deleted, remove them from the Composer files autoloaders.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/34#issuecomment-922503813
     * @throws FilesystemException
     */
    protected function cleanupFilesAutoloader(): void
    {
        if (! $this->filesystem->fileExists($this->workingDir . 'vendor/composer/autoload_files.php')) {
            return;
        }

        // TODO: dry run.

        $files = include $this->workingDir . 'vendor/composer/autoload_files.php';

        $missingFiles = array();

        foreach ($files as $file) {
            if (! $this->filesystem->fileExists($file)) {
                $missingFiles[] = str_replace([ $this->workingDir, 'vendor/composer/../', 'vendor/' ], '', $file);
                // When `composer install --no-dev` is run, it creates an index of files autoload files which
                // references the non-existent files. This causes a fatal error when the autoloader is included.
                // TODO: if delete_vendor_packages is true, do not create this file.
                $this->filesystem->write(
                    $file,
                    '<?php // This file was deleted by {@see https://github.com/BrianHenryIE/strauss}.'
                );
            }
        }

        if (empty($missingFiles)) {
            return;
        }

        $targetDirectory = $this->targetDirectory;

        foreach (array('autoload_static.php', 'autoload_files.php') as $autoloadFile) {
            $autoloadStaticPhp = $this->filesystem->read($this->workingDir . 'vendor/composer/'.$autoloadFile);

            $autoloadStaticPhpAsArray = explode(PHP_EOL, $autoloadStaticPhp);

            $newAutoloadStaticPhpAsArray = array_map(
                function (string $line) use ($missingFiles, $targetDirectory): string {
                    $containsFile = array_reduce(
                        $missingFiles,
                        function (bool $carry, string $filepath) use ($line): bool {
                            return $carry || false !== strpos($line, $filepath);
                        },
                        false
                    );

                    if (!$containsFile) {
                        return $line;
                    }

                    // TODO: Check the file does exist at the new location. It definitely should be.
                    // TODO: If the Strauss autoloader is being created, just return an empty string here.

                    return str_replace([
                        "=> __DIR__ . '/..' . '/",
                        "=> \$vendorDir . '/"
                    ], [
                        "=> __DIR__ . '/../../$targetDirectory' . '/",
                        "=> \$baseDir . '/$targetDirectory"
                    ], $line);
                },
                $autoloadStaticPhpAsArray
            );

            $newAutoloadStaticPhp = implode(PHP_EOL, $newAutoloadStaticPhpAsArray);

            $this->filesystem->write($this->workingDir . 'vendor/composer/'.$autoloadFile, $newAutoloadStaticPhp);
        }
    }
}
