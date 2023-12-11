<?php
/**
 * Deletes source files and empty directories.
 */

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RecursiveDirectoryIterator;
use Symfony\Component\Finder\Finder;

class Cleanup
{

    /** @var Filesystem */
    protected Filesystem $filesystem;

    protected string $workingDir;

    protected bool $isDeleteVendorFiles;
    protected bool $isDeleteVendorPackages;

    protected string $vendorDirectory = 'vendor'. DIRECTORY_SEPARATOR;
    
    public function __construct(StraussConfig $config, string $workingDir)
    {
        $this->vendorDirectory = $config->getVendorDirectory();
        $this->workingDir = $workingDir;

        $this->isDeleteVendorFiles = $config->isDeleteVendorFiles() && $config->getTargetDirectory() !== $config->getVendorDirectory();
        $this->isDeleteVendorPackages = $config->isDeleteVendorPackages() && $config->getTargetDirectory() !== $config->getVendorDirectory();

        $this->filesystem = new Filesystem(new LocalFilesystemAdapter($workingDir));
    }

    /**
     * Maybe delete the source files that were copied (depending on config),
     * then delete empty directories.
     *
     * @param string[] $sourceFiles Relative filepaths.
     */
    public function cleanup(array $sourceFiles): void
    {
        if (!$this->isDeleteVendorPackages && !$this->isDeleteVendorFiles) {
            return;
        }

        if ($this->isDeleteVendorPackages) {
            $package_dirs = array_unique(array_map(function (string $relativeFilePath): string {
                list( $vendor, $package ) = explode('/', $relativeFilePath);
                return "{$vendor}/{$package}";
            }, $sourceFiles));

            foreach ($package_dirs as $package_dir) {
                $relativeDirectoryPath = $this->vendorDirectory . $package_dir;

                $absolutePath = $this->workingDir . $relativeDirectoryPath;

                if (is_link($absolutePath)) {
                    unlink($absolutePath);
                }

                if ($absolutePath !== realpath($absolutePath)) {
                    continue;
                }

                $this->filesystem->deleteDirectory($relativeDirectoryPath);
            }
        } elseif ($this->isDeleteVendorFiles) {
            foreach ($sourceFiles as $sourceFile) {
                $relativeFilepath = $this->vendorDirectory . $sourceFile;

                $absolutePath = $this->workingDir . $relativeFilepath;

                if ($absolutePath !== realpath($absolutePath)) {
                    continue;
                }

                $this->filesystem->delete($relativeFilepath);
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
        $rootSourceDirectories = array_keys($rootSourceDirectories);


        $finder = new Finder();

        foreach ($rootSourceDirectories as $rootSourceDirectory) {
            if (!is_dir($rootSourceDirectory) || is_link($rootSourceDirectory)) {
                continue;
            }

            $finder->directories()->path($rootSourceDirectory);

            foreach ($finder as $directory) {
                if ($this->dirIsEmpty($directory)) {
                    $this->filesystem->deleteDirectory($directory);
                }
            }
        }
    }

    // TODO: Use Symphony or Flysystem functions.
    protected function dirIsEmpty(string $dir): bool
    {
        $di = new RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
        return iterator_count($di) === 0;
    }

    /**
     * After files are deleted, remove them from the Composer files autoloaders.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/34#issuecomment-922503813
     */
    protected function cleanupFilesAutoloader(): void
    {
        $files = include $this->workingDir . 'vendor/composer/autoload_files.php';

        $missingFiles = array();

        foreach ($files as $file) {
            if (! file_exists($file)) {
                $missingFiles[] = str_replace([ $this->workingDir, 'vendor/composer/../', 'vendor/' ], '', $file);
            }
        }

        if (empty($missingFiles)) {
            return;
        }

        foreach (array('autoload_static.php', 'autoload_files.php') as $autoloadFile) {
            $autoloadStaticPhp = $this->filesystem->read('vendor/composer/'.$autoloadFile);

            $autoloadStaticPhpAsArray = explode(PHP_EOL, $autoloadStaticPhp);

            $newAutoloadStaticPhpAsArray = array_filter(
                $autoloadStaticPhpAsArray,
                function (string $line) use ($missingFiles) {
                    return array_reduce(
                        $missingFiles,
                        function (bool $carry, string $filepath) use ($line): bool {
                            return $carry && false === strpos($line, $filepath);
                        },
                        true
                    );
                }
            );

            $newAutoloadStaticPhp = implode(PHP_EOL, $newAutoloadStaticPhpAsArray);

            $this->filesystem->write('vendor/composer/'.$autoloadFile, $newAutoloadStaticPhp);
        }
    }
}
