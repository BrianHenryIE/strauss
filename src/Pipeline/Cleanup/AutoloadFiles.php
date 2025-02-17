<?php

namespace BrianHenryIE\Strauss\Pipeline\Cleanup;

use BrianHenryIE\Strauss\Config\CleanupConfigInterface;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class AutoloadFiles {
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

    /**
     * After files are deleted, remove them from the Composer files autoloaders.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/34#issuecomment-922503813
     * @throws FilesystemException
     */
    public function cleanupFilesAutoloader(): void
    {
        if (! $this->filesystem->fileExists($this->workingDir . 'vendor/composer/autoload_files.php')) {
            $this->logger->debug('vendor/composer/autoload_files.php does not exist.');
            return;
        }

        $this->logger->info('Cleaning up autoload_files.php');

        $files = include sprintf("%s%scomposer/autoload_files.php", $this->workingDir, $this->vendorDirectory);

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

            $this->filesystem->write(
                sprintf("%s%scomposer/%s", $this->workingDir, $this->vendorDirectory, $autoloadFile),
                $newAutoloadStaticPhp
            );
        }
    }

}